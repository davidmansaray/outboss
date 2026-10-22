<?php

use App\Domain\Api\TokenScope;
use App\Domain\People\Support\ProvenanceSummary;
use App\Domain\Prospecting\DTOs\IngestionBatch;
use App\Domain\Prospecting\DTOs\IngestionObservation;
use App\Domain\Prospecting\DTOs\SourcePolicySnapshot;
use App\Domain\Prospecting\Enums\ExternalIdentityState;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\ExpireSourceObservations;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Mcp\OutbossServer;
use App\Mcp\Tools\CreateAccount as CreateAccountTool;
use App\Mcp\Tools\CreatePerson as CreatePersonTool;
use App\Mcp\Tools\ListAccounts as ListAccountsTool;
use App\Mcp\Tools\ListPeople as ListPeopleTool;
use App\Models\Account;
use App\Models\ExternalIdentity;
use App\Models\Person;
use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('encrypts external identity values while retaining deterministic hash lookup', function (): void {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $source = app(CreateProspectSource::class)->handle(
        workspace: $workspace,
        kind: 'manual',
        displayName: 'Encrypted identity source',
        actor: $user,
    );

    $result = app(ProspectIngestionService::class)->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'encrypted-identity',
        observations: [new IngestionObservation(
            entityType: 'account',
            observedValues: ['name' => 'Encrypted Co', 'website' => 'https://encrypted.example'],
            providerRecordId: 'provider-secret-value',
        )],
        actor: $user,
    ));
    $identity = ExternalIdentity::query()->where('value_hash', hash('sha256', 'provider-secret-value'))->sole();
    $raw = DB::table('external_identities')->where('id', $identity->id)->value('value');

    expect($raw)->not->toBe('provider-secret-value')
        ->and($raw)->not->toBeNull()
        ->and($identity->fresh()->value)->toBe('provider-secret-value')
        ->and($identity->value_hash)->toBe(hash('sha256', 'provider-secret-value'))
        ->and($result->accounts)->toHaveCount(1);
});

it('refreshes external identity retention and redacts only the reversible value at expiry', function (): void {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $makeSource = function (string $name, int $retentionDays) use ($workspace): ProspectSource {
        $source = ProspectSource::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'manual',
            'display_name' => $name,
            'name_normalized' => mb_strtolower($name),
            'active_name_normalized' => mb_strtolower($name),
        ]);
        $policy = array_replace(SourcePolicySnapshot::native()->policy, [
            'normalized_retention_days' => $retentionDays,
        ]);
        $version = ProspectSourceVersion::factory()->forSource($source)->create([
            'configuration' => ['provider' => 'fixture'],
            'policy_snapshot' => $policy,
            'policy_hash' => SourcePolicySnapshot::hashPolicy($policy),
        ]);
        $source->forceFill(['current_version_id' => $version->id])->save();

        return $source->fresh(['currentVersion']);
    };
    $sourceA = $makeSource('Identity retention A', 1);
    $sourceB = $makeSource('Identity retention B', 30);
    $ingestion = app(ProspectIngestionService::class);

    $first = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $sourceA,
        sourceVersion: null,
        idempotencyKey: 'identity-retention-a',
        observations: [new IngestionObservation(
            entityType: 'account',
            observedValues: ['name' => 'Retention Co', 'website' => 'https://retention.example'],
            providerRecordId: 'retained-provider-id',
        )],
        actor: $user,
    ));
    $second = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $sourceB,
        sourceVersion: null,
        idempotencyKey: 'identity-retention-b',
        observations: [new IngestionObservation(
            entityType: 'account',
            observedValues: ['name' => 'Retention Co', 'website' => 'https://retention.example'],
            providerRecordId: 'retained-provider-id',
        )],
        actor: $user,
    ));
    $identity = ExternalIdentity::query()->where('value_hash', hash('sha256', 'retained-provider-id'))->sole();
    $secondObservation = $second->observations[0];

    expect($identity->retain_until?->equalTo($secondObservation->retain_until))->toBeTrue();

    $firstObservation = $first->observations[0];
    $firstObservation->forceFill(['retain_until' => now()->subMinute()])->save();
    expect(app(ExpireSourceObservations::class)->handle($workspace, $sourceA))->toBe(1);
    expect($identity->fresh()->value)->toBe('retained-provider-id')
        ->and($identity->fresh()->retain_until?->equalTo($secondObservation->retain_until))->toBeTrue();

    $secondObservation->forceFill(['retain_until' => now()->subMinute()])->save();
    $identity->forceFill(['retain_until' => now()->subMinute()])->save();
    expect(app(ExpireSourceObservations::class)->handle($workspace, $sourceB))->toBe(1);

    $expiredIdentity = $identity->fresh();
    expect($expiredIdentity->value)->toBeNull()
        ->and($expiredIdentity->value_hash)->toBe(hash('sha256', 'retained-provider-id'))
        ->and($expiredIdentity->state)->toBe(ExternalIdentityState::Restricted);

    $collisionAccount = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Collision Co',
        'website' => 'https://collision.example',
        'domain_normalized' => 'collision.example',
    ]);
    $collision = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $sourceA,
        sourceVersion: null,
        idempotencyKey: 'identity-retention-collision',
        observations: [new IngestionObservation(
            entityType: 'account',
            observedValues: [
                'name' => 'Collision Co',
                'website' => 'https://collision.example',
            ],
            providerRecordId: 'retained-provider-id',
        )],
        actor: $user,
    ));

    expect($collision->observations[0]->safe_error_code)->toBe('external_identity_conflict')
        ->and($collisionAccount->fresh()->name)->toBe('Collision Co')
        ->and(ExternalIdentity::query()->where('value_hash', hash('sha256', 'retained-provider-id'))->value('account_id'))
        ->toBe($first->accounts[0]->id);
});

it('keeps a source-only identity expiry sweep inside that source workspace', function (): void {
    $owners = [User::factory()->create(), User::factory()->create()];
    $identities = [];
    $sources = [];

    foreach ($owners as $index => $owner) {
        $workspace = $owner->currentWorkspace();
        $source = app(CreateProspectSource::class)->handle(
            workspace: $workspace,
            kind: 'manual',
            displayName: 'Scoped expiry source '.$index,
            actor: $owner,
        );
        $result = app(ProspectIngestionService::class)->handle(new IngestionBatch(
            workspace: $workspace,
            source: $source,
            sourceVersion: null,
            idempotencyKey: 'scoped-expiry-'.$index,
            observations: [new IngestionObservation(
                entityType: 'account',
                observedValues: ['name' => 'Scoped '.$index, 'website' => 'https://scoped-'.$index.'.example'],
                providerRecordId: 'scoped-provider-'.$index,
            )],
            actor: $owner,
        ));
        $result->observations[0]->forceFill(['retain_until' => now()->subMinute()])->save();
        $identity = ExternalIdentity::query()
            ->where('workspace_id', $workspace->id)
            ->where('value_hash', hash('sha256', 'scoped-provider-'.$index))
            ->sole();
        $identity->forceFill(['retain_until' => now()->subMinute()])->save();
        $identities[] = $identity;
        $sources[] = $source;
    }

    expect(app(ExpireSourceObservations::class)->handle(null, $sources[0]))->toBe(1)
        ->and($identities[0]->fresh()->value)->toBeNull()
        ->and($identities[1]->fresh()->value)->toBe('scoped-provider-1');
});

it('does not refresh an expiring external identity from another provider', function (): void {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $makeSource = function (string $name, string $provider) use ($workspace): ProspectSource {
        $source = ProspectSource::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'manual',
            'display_name' => $name,
            'name_normalized' => mb_strtolower($name),
            'active_name_normalized' => mb_strtolower($name),
        ]);
        $policy = array_replace(SourcePolicySnapshot::native()->policy, [
            'normalized_retention_days' => 30,
        ]);
        $version = ProspectSourceVersion::factory()->forSource($source)->create([
            'configuration' => ['provider' => $provider],
            'policy_snapshot' => $policy,
            'policy_hash' => SourcePolicySnapshot::hashPolicy($policy),
        ]);
        $source->forceFill(['current_version_id' => $version->id])->save();

        return $source->fresh(['currentVersion']);
    };
    $firstSource = $makeSource('Expiry provider A', 'provider-a');
    $secondSource = $makeSource('Expiry provider B', 'provider-b');
    $ingestion = app(ProspectIngestionService::class);
    $values = ['name' => 'Provider Boundary Co', 'website' => 'https://provider-boundary.example'];

    $first = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $firstSource,
        sourceVersion: null,
        idempotencyKey: 'expiry-provider-a',
        observations: [new IngestionObservation('account', $values, providerRecordId: 'shared-provider-id')],
        actor: $user,
    ));
    $second = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $secondSource,
        sourceVersion: null,
        idempotencyKey: 'expiry-provider-b',
        observations: [new IngestionObservation('account', $values, providerRecordId: 'shared-provider-id')],
        actor: $user,
    ));
    $firstObservation = $first->observations[0];
    $firstIdentity = ExternalIdentity::query()->where('provider', 'provider-a')->sole();
    $secondIdentity = ExternalIdentity::query()->where('provider', 'provider-b')->sole();
    $firstObservation->forceFill(['retain_until' => now()->subMinute()])->save();
    $firstIdentity->forceFill(['retain_until' => now()->subMinute()])->save();

    expect(app(ExpireSourceObservations::class)->handle($workspace, $firstSource))->toBe(1)
        ->and($firstIdentity->fresh()->value)->toBeNull()
        ->and($firstIdentity->fresh()->state)->toBe(ExternalIdentityState::Restricted)
        ->and($secondIdentity->fresh()->value)->toBe('shared-provider-id')
        ->and($second->observations[0]->expired_at)->toBeNull();
});

it('omits restricted source provenance metadata while retaining counts and an omission reason', function (): void {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $makeRestricted = function (string $classification) use ($workspace, $user): array {
        $source = app(CreateProspectSource::class)->handle(
            workspace: $workspace,
            kind: 'manual',
            displayName: 'Restricted '.$classification.' source',
            policy: ['display_classification' => $classification],
            actor: $user,
        );
        $result = app(ProspectIngestionService::class)->handle(new IngestionBatch(
            workspace: $workspace,
            source: $source,
            sourceVersion: null,
            idempotencyKey: 'restricted-'.$classification,
            observations: [
                new IngestionObservation(
                    entityType: 'account',
                    observedValues: ['name' => 'Restricted '.$classification.' Co', 'website' => 'https://'.$classification.'.example'],
                ),
                new IngestionObservation(
                    entityType: 'person',
                    observedValues: [
                        'email' => $classification.'@restricted.example',
                        'full_name' => 'Restricted '.ucfirst($classification).' Person',
                    ],
                ),
            ],
            actor: $user,
        ));

        return [$source, $result->accounts[0], $result->people[0]];
    };

    foreach (['restricted', 'prohibited'] as $classification) {
        [$source, $account, $person] = $makeRestricted($classification);
        $summary = ProvenanceSummary::for($account);

        expect($summary['source_count'])->toBe(1)
            ->and($summary['primary_source'])->toBeNull()
            ->and($summary['last_observed_at'])->toBeNull()
            ->and($summary['has_restrictions'])->toBeTrue()
            ->and($summary['source_omission_reason'])->toBe('restricted_by_source_policy');

        $token = apiToken($user, [TokenScope::PeopleRead->value]);
        $this->withToken($token)
            ->getJson('/api/v1/accounts/'.$account->id)
            ->assertOk()
            ->assertJsonPath('data.provenance.source_count', 1)
            ->assertJsonPath('data.provenance.primary_source', null)
            ->assertJsonPath('data.provenance.last_observed_at', null)
            ->assertJsonPath('data.provenance.source_omission_reason', 'restricted_by_source_policy')
            ->assertDontSee((string) $source->id)
            ->assertDontSee($source->display_name);

        $this->actingAs($user)
            ->get('/prospects')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('accounts.0.provenance.source_count', 1)
                ->where('accounts.0.provenance.primary_source', null)
                ->where('accounts.0.provenance.last_observed_at', null)
                ->where('accounts.0.provenance.source_omission_reason', 'restricted_by_source_policy'));

        OutbossServer::actingAs(mcpUser($user, [TokenScope::PeopleRead->value]))
            ->tool(ListAccountsTool::class)
            ->assertOk()
            ->assertSee(['source_omission_reason', 'restricted_by_source_policy'])
            ->assertDontSee([(string) $source->id, $source->display_name]);

        $personSummary = ProvenanceSummary::for($person);
        expect($personSummary['source_count'])->toBe(1)
            ->and($personSummary['primary_source'])->toBeNull()
            ->and($personSummary['last_observed_at'])->toBeNull()
            ->and($personSummary['has_restrictions'])->toBeTrue()
            ->and($personSummary['source_omission_reason'])->toBe('restricted_by_source_policy');

        $this->withToken($token)
            ->getJson('/api/v1/people/'.$person->id)
            ->assertOk()
            ->assertJsonPath('data.provenance.source_count', 1)
            ->assertJsonPath('data.provenance.primary_source', null)
            ->assertJsonPath('data.provenance.last_observed_at', null)
            ->assertJsonPath('data.provenance.source_omission_reason', 'restricted_by_source_policy')
            ->assertDontSee((string) $source->id)
            ->assertDontSee($source->display_name);

        $this->actingAs($user)
            ->get('/prospects')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('people', fn ($people): bool => collect($people)->contains(
                    fn (array $row): bool => $row['id'] === $person->id
                        && $row['provenance']['source_count'] === 1
                        && $row['provenance']['primary_source'] === null
                        && $row['provenance']['last_observed_at'] === null
                        && $row['provenance']['source_omission_reason'] === 'restricted_by_source_policy',
                )));

        OutbossServer::actingAs(mcpUser($user, [TokenScope::PeopleRead->value]))
            ->tool(ListPeopleTool::class)
            ->assertOk()
            ->assertSee(['source_omission_reason', 'restricted_by_source_policy'])
            ->assertDontSee([(string) $source->id, $source->display_name]);
    }
});

it('replays REST and MCP account/person creates and conflicts on changed payloads', function (): void {
    $user = User::factory()->create();
    $token = apiToken($user, [TokenScope::PeopleRead->value, TokenScope::PeopleWrite->value]);

    $accountPayload = ['name' => 'Retry Account', 'website' => 'https://retry-account.example'];
    $firstAccount = $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'retry-rest-account'])
        ->postJson('/api/v1/accounts', $accountPayload)
        ->assertCreated();
    $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'retry-rest-account'])
        ->postJson('/api/v1/accounts', $accountPayload)
        ->assertCreated()
        ->assertExactJson($firstAccount->json());
    $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'retry-rest-account'])
        ->postJson('/api/v1/accounts', [...$accountPayload, 'name' => 'Changed Account'])
        ->assertConflict()
        ->assertJsonPath('error.type', 'source_idempotency_conflict');

    $personPayload = ['email' => 'retry-person@example.test', 'full_name' => 'Retry Person'];
    $firstPerson = $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'retry-rest-person'])
        ->postJson('/api/v1/people', $personPayload)
        ->assertCreated();
    $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'retry-rest-person'])
        ->postJson('/api/v1/people', $personPayload)
        ->assertCreated()
        ->assertExactJson($firstPerson->json());
    $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'retry-rest-person'])
        ->postJson('/api/v1/people', [...$personPayload, 'full_name' => 'Changed Person'])
        ->assertConflict()
        ->assertJsonPath('error.type', 'source_idempotency_conflict');

    $mcp = mcpUser($user, [TokenScope::PeopleRead->value, TokenScope::PeopleWrite->value]);
    $mcpAccountPayload = ['name' => 'MCP Retry Account', 'website' => 'https://mcp-retry-account.example'];
    $mcpAccount = OutbossServer::actingAs($mcp)
        ->tool(CreateAccountTool::class, [
            ...$mcpAccountPayload,
            'idempotency_key' => 'retry-mcp-account',
        ])
        ->assertOk();
    $account = Account::query()->where('workspace_id', $user->currentWorkspace()->id)->where('name', 'MCP Retry Account')->sole();
    OutbossServer::actingAs($mcp)
        ->tool(CreateAccountTool::class, [
            ...$mcpAccountPayload,
            'idempotency_key' => 'retry-mcp-account',
        ])
        ->assertOk()
        ->assertSee((string) $account->id);
    OutbossServer::actingAs($mcp)
        ->tool(CreateAccountTool::class, [
            ...$mcpAccountPayload,
            'name' => 'MCP Changed Account',
            'idempotency_key' => 'retry-mcp-account',
        ])
        ->assertHasErrors(['source_idempotency_conflict']);
    $mcpAccount->assertSee((string) $account->id);

    $mcpPerson = OutbossServer::actingAs($mcp)
        ->tool(CreatePersonTool::class, [
            'email' => 'retry-mcp-person@example.test',
            'full_name' => 'MCP Retry Person',
            'idempotency_key' => 'retry-mcp-person',
        ])
        ->assertOk();
    $person = Person::query()->where('workspace_id', $user->currentWorkspace()->id)->where('email_normalized', 'retry-mcp-person@example.test')->sole();
    OutbossServer::actingAs($mcp)
        ->tool(CreatePersonTool::class, [
            'email' => 'retry-mcp-person@example.test',
            'full_name' => 'MCP Retry Person',
            'idempotency_key' => 'retry-mcp-person',
        ])
        ->assertOk()
        ->assertSee((string) $person->id);
    OutbossServer::actingAs($mcp)
        ->tool(CreatePersonTool::class, [
            'email' => 'retry-mcp-person@example.test',
            'full_name' => 'MCP Changed Person',
            'idempotency_key' => 'retry-mcp-person',
        ])
        ->assertHasErrors(['source_idempotency_conflict']);
});
