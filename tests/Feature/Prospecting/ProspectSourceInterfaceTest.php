<?php

use App\Domain\People\Support\ProspectSourceSurface;
use App\Domain\Prospecting\DTOs\IngestionBatch;
use App\Domain\Prospecting\DTOs\IngestionObservation;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Jobs\Prospecting\DeleteProspectSourceData;
use App\Models\AccountSourceMembership;
use App\Models\PersonSourceMembership;
use App\Models\ProspectSource;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, workspace: Workspace, source: ProspectSource}
 */
function makeSessionSource(): array
{
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $source = app(CreateProspectSource::class)->handle(
        workspace: $workspace,
        kind: 'manual',
        displayName: 'Session source',
        configuration: ['ingress' => 'web', 'system_managed' => false],
        policy: [
            'prohibited_uses' => ['outside_prospecting'],
            'display_requirements' => ['show_attribution'],
            'policy_expires_at' => now()->addDays(10)->toIso8601String(),
        ],
        actor: $owner,
        origin: 'user',
    );

    return compact('owner', 'workspace', 'source');
}

it('lets workspace members inspect sources but only owners mutate them', function (): void {
    ['owner' => $owner, 'workspace' => $workspace, 'source' => $source] = makeSessionSource();
    expect(ProspectSourceSurface::detail($workspace, (string) $source->id)['retention_warning'])
        ->toContain('Policy review is due');
    $member = User::factory()->create();
    $workspace->memberships()->create([
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->get(route('prospect-sources.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('prospect-sources/Index')
            ->has('sources', 1)
            ->where('sources.0.id', $source->id)
            ->where('sources.0.display_name', 'Session source'));

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->get(route('prospect-sources.show', $source))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('prospect-sources/Show')
            ->where('source.id', $source->id)
            ->where('source.restrictions', ['prohibited uses', 'display requirements']));

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->post(route('prospect-sources.store'), [
            'display_name' => 'Member cannot create',
            'kind' => 'manual',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->patch(route('prospect-sources.update', $source), ['display_name' => 'Member cannot update'])
        ->assertForbidden();
});

it('creates a source, starts a session run, and serves its safe detail projection', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();

    $this->actingAs($owner)
        ->from(route('prospect-sources.index'))
        ->post(route('prospect-sources.store'), [
            'display_name' => 'Imported session source',
            'kind' => 'manual',
            'configuration' => ['ingress' => 'web', 'system_managed' => false],
            'policy' => ['prohibited_uses' => ['resale']],
        ])
        ->assertRedirect(route('prospect-sources.index'))
        ->assertSessionHas('success', 'Source created.');

    $source = ProspectSource::query()
        ->where('workspace_id', $workspace->id)
        ->where('display_name', 'Imported session source')
        ->firstOrFail();

    $this->actingAs($owner)
        ->get(route('prospect-sources.show', $source))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('prospect-sources/Show')
            ->where('source.id', $source->id)
            ->where('source.display_name', 'Imported session source')
            ->has('source.versions', 1)
            ->has('source.runs', 0));

    $runResponse = $this->actingAs($owner)
        ->postJson(route('prospect-sources.runs.store', $source), [
            'idempotency_key' => 'session-interface-run',
            'input_reference' => ['transport' => 'session'],
            'input' => [
                [
                    'entity_type' => 'account',
                    'row_number' => 1,
                    'observation_key' => 'session-account-1',
                    'observed_values' => [
                        'name' => 'Session Acme',
                        'website' => 'https://session-acme.example',
                    ],
                ],
                [
                    'entity_type' => 'person',
                    'row_number' => 2,
                    'observation_key' => 'session-person-1',
                    'observed_values' => [
                        'email' => 'maya@session-acme.example',
                        'full_name' => 'Maya Session',
                    ],
                ],
            ],
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.state', 'completed')
        ->assertJsonPath('data.received', 2)
        ->assertJsonPath('data.created', 2);
    $runId = $runResponse->json('data.id');

    $this->actingAs($owner)
        ->getJson(route('prospect-sources.runs.show', [$source, $runId]))
        ->assertOk()
        ->assertJsonPath('data.id', $runId)
        ->assertJsonPath('data.observations.meta.total', 2)
        ->assertJsonPath('data.observations.data.0.row_number', 1)
        ->assertJsonMissingPath('data.observations.data.0.observed_values')
        ->assertJsonMissingPath('data.observations.data.0.provider_record_id')
        ->assertJsonMissingPath('data.observations.data.0.safe_error_message');

    $this->actingAs($owner)
        ->get(route('prospect-sources.show', $source))
        ->assertInertia(fn (Assert $page) => $page
            ->where('source.run_count', 1)
            ->where('source.runs.0.id', $runId)
            ->where('source.runs.0.received', 2));
});

it('hides canonical ids for restricted and prohibited display policies on the session read surface', function (): void {
    ['owner' => $owner, 'workspace' => $workspace] = makeSessionSource();

    foreach (['restricted', 'prohibited'] as $classification) {
        $source = app(CreateProspectSource::class)->handle(
            workspace: $workspace,
            kind: 'manual',
            displayName: 'Session '.$classification.' source',
            configuration: ['ingress' => 'web', 'system_managed' => false],
            policy: ['display_classification' => $classification],
            actor: $owner,
            origin: 'user',
        );
        $runResponse = $this->actingAs($owner)->postJson(route('prospect-sources.runs.store', $source), [
            'idempotency_key' => 'session-policy-'.$classification,
            'input' => [[
                'entity_type' => 'account',
                'row_number' => 1,
                'observation_key' => 'session-policy-'.$classification,
                'observed_values' => [
                    'name' => 'Synthetic '.$classification.' account',
                    'website' => 'https://'.$classification.'.policy.example',
                ],
            ]],
        ])->assertStatus(202);
        $runId = $runResponse->json('data.id');
        $observation = SourceObservation::query()->where('source_run_id', $runId)->sole();

        expect($observation->account_id)->not->toBeNull();
        $read = $this->actingAs($owner)
            ->getJson(route('prospect-sources.runs.show', [$source, $runId]))
            ->assertOk();
        $payload = $read->json('data.observations.data.0');

        expect($payload['account_id'])->toBeNull()
            ->and($payload['person_id'])->toBeNull()
            ->and($payload['display_classification'])->toBe($classification)
            ->and($payload['display_omission_reason'])->toBe('restricted_by_source_policy');
        $read->assertDontSee('Synthetic '.$classification.' account');
    }
});

it('returns 404 for session source and run identifiers from another workspace', function (): void {
    ['owner' => $owner, 'source' => $source] = makeSessionSource();
    $foreign = User::factory()->create();
    $foreignSource = app(CreateProspectSource::class)->handle(
        workspace: $foreign->currentWorkspace(),
        kind: 'manual',
        displayName: 'Foreign source',
        actor: $foreign,
    );
    $foreignRun = SourceRun::factory()->forSource($foreignSource)->create([
        'workspace_id' => $foreign->currentWorkspace()->id,
    ]);

    $this->actingAs($owner)
        ->get(route('prospect-sources.show', $foreignSource))
        ->assertNotFound();

    $this->actingAs($owner)
        ->get(route('prospect-sources.runs.show', [$source, $foreignRun]))
        ->assertNotFound();

    $this->actingAs($owner)
        ->postJson(route('prospect-sources.runs.store', $foreignSource), [
            'idempotency_key' => 'cross-workspace-run',
        ])
        ->assertNotFound();
});

it('redirects session source mutations for Inertia callers while retaining JSON APIs', function (): void {
    Queue::fake();
    ['owner' => $owner, 'workspace' => $workspace, 'source' => $source] = makeSessionSource();
    $inertiaHeaders = [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ];

    $start = $this->actingAs($owner)
        ->from(route('prospect-sources.show', $source))
        ->withHeaders($inertiaHeaders)
        ->post(route('prospect-sources.runs.store', $source), [
            'idempotency_key' => 'inertia-source-run',
            'input_reference' => ['transport' => 'session'],
        ]);
    $start->assertRedirect(route('prospect-sources.show', $source))
        ->assertSessionHas('success', 'Source run started.');
    $run = SourceRun::query()->where('prospect_source_id', $source->id)->firstOrFail();

    $this->actingAs($owner)
        ->from(route('prospect-sources.runs.show', [$source, $run]))
        ->withHeaders($inertiaHeaders)
        ->post(route('prospect-sources.runs.commit', [$source, $run]), [])
        ->assertRedirect(route('prospect-sources.runs.show', [$source, $run]))
        ->assertSessionHas('success', 'Source run committed.');

    $failed = SourceRun::factory()->forSource($source)->create([
        'workspace_id' => $workspace->id,
        'state' => 'failed',
        'input_reference' => ['transport' => 'session', 'retry' => true],
    ]);
    $this->actingAs($owner)
        ->from(route('prospect-sources.runs.show', [$source, $failed]))
        ->withHeaders($inertiaHeaders)
        ->post(route('prospect-sources.runs.retry', [$source, $failed]), [])
        ->assertRedirect(route('prospect-sources.runs.show', [$source, $failed]))
        ->assertSessionHas('success', 'Source run retry started.');

    $this->actingAs($owner)
        ->from(route('prospect-sources.show', $source))
        ->withHeaders($inertiaHeaders)
        ->delete(route('prospect-sources.data.destroy', $source), [])
        ->assertRedirect(route('prospect-sources.show', $source))
        ->assertSessionHas('success', 'Source data deletion queued.');

    Queue::assertPushed(DeleteProspectSourceData::class);
});

it('exports only bounded source summaries with no-store headers and queues owner deletion', function (): void {
    Queue::fake();
    ['owner' => $owner, 'workspace' => $workspace, 'source' => $source] = makeSessionSource();
    $run = SourceRun::factory()->forSource($source)->create([
        'workspace_id' => $workspace->id,
        'received' => 4,
        'accepted' => 3,
        'created' => 2,
        'linked' => 1,
        'suppressed' => 1,
        'rejected' => 1,
    ]);
    SourceObservation::factory()->forSourceRun($run)->create([
        'workspace_id' => $workspace->id,
        'prospect_source_id' => $source->id,
        'prospect_source_version_id' => $run->prospect_source_version_id,
        'disposition' => 'rejected',
        'safe_error_code' => 'missing_email',
        'observed_values' => ['email' => 'private@session-acme.example'],
        'provider_record_id' => 'provider-secret',
        'canonical_url' => 'https://private.session-acme.example',
    ]);

    $errors = $this->actingAs($owner)
        ->get(route('prospect-sources.runs.errors', [$source, $run]));

    $errors->assertOk();
    expect($errors->headers->get('cache-control'))->toContain('no-store')
        ->and($errors->streamedContent())->toContain('missing_email')
        ->and($errors->streamedContent())->not->toContain('private@session-acme.example')
        ->and($errors->streamedContent())->not->toContain('provider-secret');

    $export = $this->actingAs($owner)
        ->get(route('prospect-sources.export', $source));

    $export->assertOk();
    $this->assertDatabaseHas('workspace_data_export_events', [
        'workspace_id' => $workspace->id,
        'prospect_source_id' => $source->id,
        'user_id' => $owner->id,
        'export_type' => 'prospect_source',
        'policy_hash' => $source->currentVersion()->value('policy_hash'),
    ]);
    $exportContents = $export->streamedContent();
    expect($export->headers->get('cache-control'))->toContain('no-store')
        ->and($exportContents)->toContain('record_type,source_id,source_name')
        ->and($exportContents)->toContain((string) $source->current_version_id)
        ->and($exportContents)->toContain((string) $run->id)
        ->and($exportContents)->toContain('observation')
        ->and($exportContents)->toContain('private@session-acme.example')
        ->and($export->streamedContent())->not->toContain('provider-secret')
        ->and($export->streamedContent())->not->toContain('private.session-acme.example');

    $this->actingAs($owner)
        ->deleteJson(route('prospect-sources.data.destroy', $source), ['delete_canonical' => false])
        ->assertStatus(202)
        ->assertJsonPath('data.queued', true);

    Queue::assertPushed(DeleteProspectSourceData::class, fn (DeleteProspectSourceData $job): bool => $job->workspaceId === $workspace->id
        && $job->sourceId === $source->id
        && $job->deleteCanonical === false
    );
});

it('omits canonical ids from restricted source observation and membership exports', function (): void {
    ['owner' => $owner, 'workspace' => $workspace] = makeSessionSource();
    $source = app(CreateProspectSource::class)->handle(
        workspace: $workspace,
        kind: 'manual',
        displayName: 'Restricted export source',
        configuration: ['ingress' => 'web', 'system_managed' => false],
        policy: ['export_classification' => 'restricted'],
        actor: $owner,
        origin: 'user',
    );
    $result = app(ProspectIngestionService::class)->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'restricted-export-ids',
        observations: [
            new IngestionObservation(
                entityType: 'account',
                observedValues: [
                    'name' => 'Restricted Export Co',
                    'website' => 'https://restricted-export.example',
                ],
                observationKey: 'restricted-export-account',
            ),
            new IngestionObservation(
                entityType: 'person',
                observedValues: [
                    'email' => 'restricted-export@example.test',
                    'full_name' => 'Restricted Export Person',
                ],
                observationKey: 'restricted-export-person',
            ),
        ],
        actor: $owner,
        initiatorType: 'user',
    ));

    expect($result->accounts)->toHaveCount(1)
        ->and($result->people)->toHaveCount(1)
        ->and(AccountSourceMembership::query()->where('prospect_source_id', $source->id)->count())->toBe(1)
        ->and(PersonSourceMembership::query()->where('prospect_source_id', $source->id)->count())->toBe(1);

    $contents = $this->actingAs($owner)
        ->get(route('prospect-sources.export', $source))
        ->assertOk()
        ->streamedContent();
    $lines = preg_split('/\r\n|\n|\r/', trim($contents));
    $headers = str_getcsv((string) array_shift($lines));
    $records = array_map(function (string $line) use ($headers): array {
        $values = str_getcsv($line);
        $record = [];
        foreach ($headers as $index => $header) {
            $record[$header] = $values[$index] ?? '';
        }

        return $record;
    }, $lines);
    $observations = array_values(array_filter($records, static fn (array $record): bool => $record['record_type'] === 'observation'));
    $memberships = array_values(array_filter($records, static fn (array $record): bool => in_array(
        $record['record_type'],
        ['account_membership', 'person_membership'],
        true,
    )));

    expect($observations)->toHaveCount(2)
        ->and($memberships)->toHaveCount(2);
    foreach ($observations as $record) {
        expect($record['account_id'])->toBe('')
            ->and($record['person_id'])->toBe('')
            ->and($record['omission_reason'])->toBe('restricted_by_observation_policy');
    }
    foreach ($memberships as $record) {
        expect($record['account_id'])->toBe('')
            ->and($record['person_id'])->toBe('')
            ->and($record['omission_reason'])->toBe('restricted_by_source_policy');
    }
});

it('restricts source exports to owners and policy-allowed sources', function (): void {
    ['owner' => $owner, 'workspace' => $workspace, 'source' => $source] = makeSessionSource();
    $member = User::factory()->create();
    $workspace->memberships()->create([
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->get(route('prospect-sources.export', $source))
        ->assertForbidden();

    $blocked = app(CreateProspectSource::class)->handle(
        workspace: $workspace,
        kind: 'manual',
        displayName: 'Export blocked source',
        policy: ['export_allowed' => false],
        actor: $owner,
        origin: 'user',
    );

    $this->actingAs($owner)
        ->get(route('prospect-sources.export', $blocked))
        ->assertForbidden();
});

it('keeps source projections workspace-scoped when the active session workspace changes', function (): void {
    $owner = User::factory()->create();
    $firstWorkspace = $owner->currentWorkspace();
    $secondWorkspace = Workspace::factory()->create(['name' => 'Second workspace', 'type' => 'team']);
    $secondWorkspace->memberships()->create([
        'user_id' => $owner->id,
        'role' => WorkspaceMembership::ROLE_OWNER,
    ]);
    $firstSource = app(CreateProspectSource::class)->handle($firstWorkspace, 'manual', 'First source', actor: $owner);
    $secondSource = app(CreateProspectSource::class)->handle($secondWorkspace, 'manual', 'Second source', actor: $owner);

    $this->actingAs($owner)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $secondWorkspace->id])
        ->get(route('prospect-sources.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sources', 1)
            ->where('sources.0.id', $secondSource->id));

    expect(ProspectSourceSurface::find($secondWorkspace, $firstSource->id))->toBeNull();
});
