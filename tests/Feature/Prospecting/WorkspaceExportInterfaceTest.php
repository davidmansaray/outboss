<?php

use App\Domain\Integrations\Enums\IntegrationOperationStatus;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Models\ExternalOperation;
use App\Models\IntegrationConnection;
use App\Models\IntegrationProviderRegistration;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\User;

it('exports safe source and integration summaries only to workspace owners', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $source = app(CreateProspectSource::class)->handle(
        workspace: $workspace,
        kind: 'manual',
        displayName: 'Exported source',
        actor: $owner,
        origin: 'user',
    );
    $run = SourceRun::factory()->forSource($source)->create([
        'workspace_id' => $workspace->id,
    ]);
    SourceObservation::factory()->forSourceRun($run)->create([
        'workspace_id' => $workspace->id,
        'prospect_source_id' => $source->id,
        'prospect_source_version_id' => $run->prospect_source_version_id,
        'observed_values' => ['email' => 'raw-observation-secret@example.test'],
        'provider_record_id' => 'raw-provider-record-secret',
    ]);

    $connection = IntegrationConnection::factory()->create([
        'workspace_id' => $workspace->id,
        'provider' => 'private-provider',
    ]);
    $registration = IntegrationProviderRegistration::factory()->create([
        'workspace_id' => $workspace->id,
        'connection_id' => $connection->id,
        'provider' => 'private-provider',
    ]);
    ExternalOperation::factory()->create([
        'workspace_id' => $workspace->id,
        'connection_id' => $connection->id,
        'registration_id' => $registration->id,
        'provider' => 'private-provider',
        'status' => IntegrationOperationStatus::Succeeded,
        'idempotency_key' => 'private-operation-idempotency-secret',
        'request_fingerprint' => 'private-request-fingerprint-secret',
        'provider_request_id' => 'private-provider-request-secret',
        'safe_metadata' => ['private' => 'private-operation-metadata-secret'],
        'result_reference' => ['email' => 'private-provider-result-secret@example.test'],
        'reserved_cost_micros' => 1200,
        'actual_cost_micros' => 900,
    ]);

    $member = User::factory()->create();
    $workspace->memberships()->create([
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->get(route('exports.sources'))
        ->assertForbidden();
    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->get(route('exports.integrations'))
        ->assertForbidden();

    $sourceExport = $this->actingAs($owner)->get(route('exports.sources'));
    $sourceExport->assertOk();
    $this->assertDatabaseHas('workspace_data_export_events', [
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'export_type' => 'prospect_sources',
    ]);
    $sourceContents = $sourceExport->streamedContent();
    expect($sourceExport->headers->get('cache-control'))->toContain('no-store')
        ->and($sourceContents)->toContain('source_id,source_name,kind,status')
        ->and($sourceContents)->toContain((string) $source->id)
        ->and($sourceContents)->toContain('Exported source')
        ->and($sourceContents)->not->toContain('raw-observation-secret@example.test')
        ->and($sourceContents)->not->toContain('raw-provider-record-secret');

    $integrationExport = $this->actingAs($owner)->get(route('exports.integrations'));
    $integrationExport->assertOk();
    $integrationContents = $integrationExport->streamedContent();
    expect($integrationExport->headers->get('cache-control'))->toContain('no-store')
        ->and($integrationContents)->toContain('provider,connection_status,registration_count')
        ->and($integrationContents)->toContain('private-provider')
        ->and($integrationContents)->not->toContain('private-operation-idempotency-secret')
        ->and($integrationContents)->not->toContain('private-request-fingerprint-secret')
        ->and($integrationContents)->not->toContain('private-provider-request-secret')
        ->and($integrationContents)->not->toContain('private-operation-metadata-secret')
        ->and($integrationContents)->not->toContain('private-provider-result-secret@example.test');
});

it('keeps source and integration export routes workspace-scoped', function (): void {
    $owner = User::factory()->create();
    $foreign = User::factory()->create();
    $source = app(CreateProspectSource::class)->handle(
        workspace: $owner->currentWorkspace(),
        kind: 'manual',
        displayName: 'Owner source',
        actor: $owner,
    );
    app(CreateProspectSource::class)->handle(
        workspace: $foreign->currentWorkspace(),
        kind: 'manual',
        displayName: 'Foreign source must stay hidden',
        actor: $foreign,
    );
    IntegrationConnection::factory()->create([
        'workspace_id' => $foreign->currentWorkspace()->id,
        'provider' => 'foreign-provider-must-stay-hidden',
    ]);

    $sourceExport = $this->actingAs($owner)->get(route('exports.sources'));
    $integrationExport = $this->actingAs($owner)->get(route('exports.integrations'));

    expect($sourceExport->streamedContent())->toContain((string) $source->id)
        ->and($sourceExport->streamedContent())->not->toContain('Foreign source must stay hidden')
        ->and($integrationExport->streamedContent())->not->toContain('foreign-provider-must-stay-hidden');
});
