<?php

use App\Domain\Api\TokenScope;
use App\Domain\People\Support\ProspectSourceSurface;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Models\ProspectSource;
use App\Models\SourceObservation;
use App\Models\User;
use App\Models\Workspace;

/**
 * @return array{owner: User, workspace: Workspace, source: ProspectSource}
 */
function makeApiSource(): array
{
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $source = app(CreateProspectSource::class)->handle(
        workspace: $workspace,
        kind: 'manual',
        displayName: 'REST source',
        configuration: ['ingress' => 'rest', 'system_managed' => false],
        policy: [
            'prohibited_uses' => ['resale'],
            'display_requirements' => ['show_attribution'],
        ],
        actor: $owner,
        origin: 'api',
    );

    return compact('owner', 'workspace', 'source');
}

/**
 * Create an API token while explicitly binding it to the requested
 * workspace. This keeps member-token tests independent of browser session
 * state, which is deliberately not present on REST requests.
 *
 * @param  list<string>  $scopes
 */
function apiTokenForWorkspace(User $user, Workspace $workspace, array $scopes): string
{
    $created = $user->createToken('source-api-test', $scopes);
    $created->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

    return $created->plainTextToken;
}

it('requires authentication and the source scopes on the REST surface', function (): void {
    $this->getJson('/api/v1/prospect-sources')
        ->assertUnauthorized()
        ->assertJsonPath('error.type', 'unauthenticated');

    $owner = User::factory()->create();

    $this->withToken(apiToken($owner, [TokenScope::PeopleRead->value]))
        ->getJson('/api/v1/prospect-sources')
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');

    $this->withToken(apiToken($owner, [TokenScope::SourcesRead->value]))
        ->postJson('/api/v1/prospect-sources', [
            'display_name' => 'Read token cannot write',
            'kind' => 'manual',
        ])
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');
});

it('allows source reads to members but reserves REST source writes for owners', function (): void {
    ['owner' => $owner, 'workspace' => $workspace, 'source' => $source] = makeApiSource();
    $member = User::factory()->create();
    $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
    $readToken = apiTokenForWorkspace($member, $workspace, [TokenScope::SourcesRead->value]);
    $writeToken = apiTokenForWorkspace($member, $workspace, [TokenScope::SourcesWrite->value]);

    $this->withToken($readToken)
        ->getJson('/api/v1/prospect-sources/'.$source->id)
        ->assertOk()
        ->assertJsonPath('data.id', $source->id)
        ->assertJsonPath('data.display_name', 'REST source');

    $this->withToken($writeToken)
        ->patchJson('/api/v1/prospect-sources/'.$source->id, ['display_name' => 'Member update'])
        ->assertForbidden();

    $this->withToken($writeToken)
        ->postJson('/api/v1/prospect-sources', [
            'display_name' => 'Member create',
            'kind' => 'manual',
        ])
        ->assertForbidden();

    expect($source->fresh()->display_name)->toBe('REST source')
        ->and(ProspectSource::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('returns 404 rather than disclosing a source or run from another workspace', function (): void {
    ['owner' => $owner, 'source' => $source] = makeApiSource();
    $foreign = User::factory()->create();
    $foreignSource = app(CreateProspectSource::class)->handle(
        workspace: $foreign->currentWorkspace(),
        kind: 'manual',
        displayName: 'Foreign REST source',
        actor: $foreign,
        origin: 'api',
    );
    $foreignRun = $foreignSource->runs()->create([
        'workspace_id' => $foreign->currentWorkspace()->id,
        'prospect_source_version_id' => $foreignSource->current_version_id,
        'state' => 'draft',
        'initiator_type' => 'api',
        'idempotency_key' => 'foreign-run',
        'request_fingerprint' => hash('sha256', 'foreign-run'),
    ]);
    $token = apiToken($owner, [TokenScope::SourcesRead->value, TokenScope::SourcesWrite->value]);

    $this->withToken($token)
        ->getJson('/api/v1/prospect-sources/'.$foreignSource->id)
        ->assertNotFound();

    $this->withToken($token)
        ->getJson('/api/v1/prospect-sources/'.$source->id.'/runs')
        ->assertOk()
        ->assertJsonPath('data', []);

    $this->withToken($token)
        ->getJson('/api/v1/source-runs/'.$foreignRun->id)
        ->assertNotFound();

    $this->withToken($token)
        ->postJson('/api/v1/prospect-sources/'.$foreignSource->id.'/runs', [], [
            'Idempotency-Key' => 'cross-workspace-run',
        ])
        ->assertNotFound();
});

it('creates REST sources idempotently and ingests an idempotent batch through the source', function (): void {
    $owner = User::factory()->create();
    $token = apiToken($owner, [TokenScope::SourcesRead->value, TokenScope::SourcesWrite->value]);
    $createPayload = [
        'display_name' => 'REST import source',
        'kind' => 'api_import',
        'configuration' => ['ingress' => 'rest', 'system_managed' => false],
        'policy' => ['prohibited_uses' => ['resale']],
    ];

    $created = $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'rest-source-create'])
        ->postJson('/api/v1/prospect-sources', $createPayload)
        ->assertCreated()
        ->assertJsonPath('data.display_name', 'REST import source')
        ->assertJsonPath('data.kind', 'api_import');
    $sourceId = $created->json('data.id');

    $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'rest-source-create'])
        ->postJson('/api/v1/prospect-sources', $createPayload)
        ->assertCreated()
        ->assertExactJson($created->json());

    $createdSource = ProspectSource::query()->whereKey($sourceId)->firstOrFail();
    expect(ProspectSource::query()->where('workspace_id', $owner->currentWorkspace()->id)->count())->toBe(1)
        ->and($createdSource->currentVersion()->firstOrFail()->origin->value)->toBe('api');

    $batchPayload = [
        'source_id' => $sourceId,
        'rows' => [
            [
                'entity_type' => 'account',
                'row_number' => 1,
                'observation_key' => 'rest-account-1',
                'observed_values' => [
                    'name' => 'REST Acme',
                    'website' => 'https://rest-acme.example',
                ],
            ],
            [
                'entity_type' => 'person',
                'row_number' => 2,
                'observation_key' => 'rest-person-1',
                'observed_values' => [
                    'email' => 'maya@rest-acme.example',
                    'full_name' => 'Maya REST',
                ],
            ],
        ],
    ];
    $batch = $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'rest-batch-once'])
        ->postJson('/api/v1/prospect-imports', $batchPayload)
        ->assertStatus(202)
        ->assertJsonPath('data.state', 'completed')
        ->assertJsonPath('data.received', 2)
        ->assertJsonPath('data.created', 2)
        ->assertJsonPath('data.replayed', false);
    $runId = $batch->json('data.run_id');

    $replayed = $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'rest-batch-once'])
        ->postJson('/api/v1/prospect-imports', $batchPayload)
        ->assertStatus(202)
        ->assertExactJson($batch->json());
    expect($replayed->json('data.run_id'))->toBe($runId);

    $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'rest-batch-once'])
        ->postJson('/api/v1/prospect-imports', [
            ...$batchPayload,
            'rows' => [[
                'entity_type' => 'person',
                'observed_values' => ['email' => 'different@rest-acme.example'],
            ]],
        ])
        ->assertConflict()
        ->assertJsonPath('error.type', 'idempotency_conflict');

    $this->withToken($token)
        ->getJson('/api/v1/prospect-sources/'.$sourceId)
        ->assertOk()
        ->assertJsonPath('data.id', $sourceId)
        ->assertJsonPath('data.run_count', 1)
        ->assertJsonPath('data.runs.0.id', $runId)
        ->assertJsonMissingPath('data.runs.0.input_reference');

    $this->withToken($token)
        ->getJson('/api/v1/source-runs/'.$runId)
        ->assertOk()
        ->assertJsonPath('data.id', $runId)
        ->assertJsonPath('data.observations.meta.total', 2)
        ->assertJsonMissingPath('data.observations.data.0.observed_values')
        ->assertJsonMissingPath('data.observations.data.0.provider_record_id');

    $this->withToken($token)
        ->getJson('/api/v1/source-runs/'.$runId.'/observations?per_page=1')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonMissingPath('data.0.observed_values');

    expect(ProspectSourceSurface::list($owner->currentWorkspace()))->toHaveCount(1);
});

it('hides canonical ids for restricted and prohibited display policies on REST observation reads', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $token = apiTokenForWorkspace($owner, $workspace, [TokenScope::SourcesRead->value, TokenScope::SourcesWrite->value]);

    foreach (['restricted', 'prohibited'] as $classification) {
        $source = app(CreateProspectSource::class)->handle(
            workspace: $workspace,
            kind: 'manual',
            displayName: 'REST '.$classification.' source',
            configuration: ['ingress' => 'rest', 'system_managed' => false],
            policy: ['display_classification' => $classification],
            actor: $owner,
            origin: 'api',
        );
        $runResponse = $this->withToken($token)->postJson('/api/v1/prospect-sources/'.$source->id.'/runs', [
            'idempotency_key' => 'rest-policy-'.$classification,
            'input' => [[
                'entity_type' => 'account',
                'row_number' => 1,
                'observation_key' => 'rest-policy-'.$classification,
                'observed_values' => [
                    'name' => 'Synthetic '.$classification.' REST account',
                    'website' => 'https://'.$classification.'.rest-policy.example',
                ],
            ]],
        ])->assertStatus(202);
        $runId = $runResponse->json('data.id');
        $observation = SourceObservation::query()->where('source_run_id', $runId)->sole();

        expect($observation->account_id)->not->toBeNull();
        $read = $this->withToken($token)
            ->getJson('/api/v1/source-runs/'.$runId.'/observations')
            ->assertOk();
        $payload = $read->json('data.0');

        expect($payload['account_id'])->toBeNull()
            ->and($payload['person_id'])->toBeNull()
            ->and($payload['display_classification'])->toBe($classification)
            ->and($payload['display_omission_reason'])->toBe('restricted_by_source_policy');
        $read->assertDontSee('Synthetic '.$classification.' REST account');
    }
});

it('requires an idempotency key on direct REST source-run starts', function (): void {
    ['owner' => $owner, 'source' => $source] = makeApiSource();
    $token = apiToken($owner, [TokenScope::SourcesWrite->value]);

    $this->withToken($token)
        ->postJson('/api/v1/prospect-sources/'.$source->id.'/runs', [
            'input_reference' => ['transport' => 'rest'],
        ])
        ->assertBadRequest();
});

it('does not accept a malformed source id as a REST source route', function (): void {
    $owner = User::factory()->create();
    $token = apiToken($owner, [TokenScope::SourcesRead->value]);

    $this->withToken($token)
        ->getJson('/api/v1/prospect-sources/not-a-uuid')
        ->assertNotFound();
});
