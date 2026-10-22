<?php

use App\Domain\Api\TokenScope;
use App\Domain\Conversions\Services\ActivateConversionDefinition;
use App\Domain\Conversions\Services\ConversionSourceConnectionManager;
use App\Domain\Conversions\Services\CreateConversionDefinition;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ConversionDefinition;
use App\Models\ConversionEvent;
use App\Models\ConversionEventReceipt;
use App\Models\Message;
use App\Models\OutboxEvent;
use App\Models\SendAttempt;
use App\Models\Thread;
use App\Models\User;
use App\Models\WorkspaceClientOperation;
use App\Models\WorkspaceMembership;
use Illuminate\Testing\TestResponse;

it('manages conversion source credentials through an owner-scoped REST contract', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $makeDefinition = static function (User $actor, string $eventKey): ConversionDefinition {
        $workspace = $actor->currentWorkspace();
        $definition = app(CreateConversionDefinition::class)->handle($workspace, $actor, [
            'event_key' => $eventKey,
            'display_name' => 'Webhook acceptance',
            'description' => 'A signed acceptance received from a source.',
            'subject_type' => 'Account',
            'allowed_sources' => ['webhook'],
            'property_schema' => [
                'type' => 'object',
                'properties' => [],
                'required' => [],
                'additionalProperties' => false,
            ],
            'allowed_identity_keys' => ['account_id'],
            'dedupe_strategy' => 'source_and_external_id',
            'dedupe_configuration' => [],
            'attribution_window' => ['days' => 30],
            'value_policy' => ['allowed' => false, 'currency' => null],
            'correction_policy' => ['allowed' => true],
            'reversal_policy' => ['allowed' => true],
            'retention_policy' => ['unmatched_identity_days' => 30],
            'export_policy' => ['allowed' => true],
        ]);

        return app(ActivateConversionDefinition::class)->handle($definition, $actor);
    };
    $definition = $makeDefinition($owner, 'rest_connection_management');
    $token = apiToken($owner, [TokenScope::ConversionConnectionsWrite->value]);

    $created = $this->withToken($token)->postJson(
        '/api/v1/conversion-connections',
        [
            'kind' => 'webhook',
            'secret' => 'first-transport-secret',
            'display_name' => 'REST acceptance source',
            'allowed_conversion_definition_ids' => [$definition->id],
            'allowed_source_types' => ['webhook'],
            'payload_limit_bytes' => 4096,
            'replay_window_seconds' => 300,
            'rotation_overlap_seconds' => 600,
        ],
        ['Idempotency-Key' => 'rest-connection-create'],
    )->assertCreated();
    $connectionId = (string) $created->json('data.id');
    $publicId = (string) $created->json('data.public_id');

    expect($created->json('data.status'))->toBe('active')
        ->and($created->json('data.kind'))->toBe('webhook')
        ->and($created->json('data.webhook_url'))->toEndWith('/webhooks/v1/conversions/'.$publicId)
        ->and($created->json('data.current_version.allowed_conversion_definition_ids'))->toBe([$definition->id])
        ->and($created->json('data.secret'))->toBe('first-transport-secret')
        ->and($created->headers->get('Cache-Control'))->toContain('no-store')
        ->and($created->json('data.current_version'))->not->toHaveKey('encrypted_secret')
        ->and(substr_count($created->getContent(), 'first-transport-secret'))->toBe(1);

    $replayedCreation = $this->withToken($token)->postJson(
        '/api/v1/conversion-connections',
        [
            'kind' => 'webhook',
            'secret' => 'first-transport-secret',
            'display_name' => 'REST acceptance source',
            'allowed_conversion_definition_ids' => [$definition->id],
            'allowed_source_types' => ['webhook'],
            'payload_limit_bytes' => 4096,
            'replay_window_seconds' => 300,
            'rotation_overlap_seconds' => 600,
        ],
        ['Idempotency-Key' => 'rest-connection-create'],
    )->assertCreated();
    expect($replayedCreation->json('data.id'))->toBe($connectionId)
        ->and($replayedCreation->json('data'))->not->toHaveKey('secret');

    $rotated = $this->withToken($token)->postJson(
        "/api/v1/conversion-connections/{$connectionId}/rotate",
        [
            'secret' => 'second-transport-secret',
            'expected_lock_version' => 0,
            'rotation_overlap_seconds' => 600,
        ],
        ['Idempotency-Key' => 'rest-connection-rotate'],
    )->assertOk();
    expect($rotated->json('data.lock_version'))->toBe(1)
        ->and($rotated->json('data.current_version.version'))->toBe(2)
        ->and($rotated->json('data.secret'))->toBe('second-transport-secret')
        ->and(substr_count($rotated->getContent(), 'second-transport-secret'))->toBe(1);

    $this->withToken($token)->postJson(
        "/api/v1/conversion-connections/{$connectionId}/disable",
        ['expected_lock_version' => 1],
        ['Idempotency-Key' => 'rest-connection-disable'],
    )->assertOk()->assertJsonPath('data.status', 'disabled');
});

it('keeps conversion connection administration least-privilege and workspace-scoped', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $connection = app(ConversionSourceConnectionManager::class)->connect(
        $workspace,
        'webhook',
        'owner-secret',
        $owner,
    );
    $readOnlyConversionToken = apiToken($owner, [TokenScope::ConversionsWrite->value]);

    $this->withToken($readOnlyConversionToken)->postJson(
        '/api/v1/conversion-connections',
        ['kind' => 'webhook', 'secret' => 'must-not-be-accepted'],
        ['Idempotency-Key' => 'rest-connection-denied'],
    )->assertForbidden()->assertJsonPath('error.type', 'insufficient_scope');

    $member = User::factory()->withoutWorkspace()->create();
    WorkspaceMembership::query()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);
    $memberTokenResult = $member->createToken('connection-member-test', [TokenScope::ConversionConnectionsWrite->value]);
    $memberTokenResult->accessToken->forceFill(['workspace_id' => $workspace->id])->save();
    $memberToken = $memberTokenResult->plainTextToken;
    $this->withToken($memberToken)->postJson(
        "/api/v1/conversion-connections/{$connection->id}/rotate",
        ['secret' => 'member-must-not-rotate'],
        ['Idempotency-Key' => 'rest-connection-member-rotate'],
    )->assertForbidden();

    $foreignOwner = User::factory()->create();
    $foreignConnection = app(ConversionSourceConnectionManager::class)->connect(
        $foreignOwner->currentWorkspace(),
        'webhook',
        'foreign-secret',
        $foreignOwner,
    );
    $this->withToken($readOnlyConversionToken)->postJson(
        "/api/v1/conversion-connections/{$foreignConnection->id}/rotate",
        ['secret' => 'cross-workspace-must-not-rotate'],
        ['Idempotency-Key' => 'rest-connection-cross-workspace'],
    )->assertForbidden();

    $ownerToken = apiToken($owner, [TokenScope::ConversionConnectionsWrite->value]);
    app('auth')->forgetGuards();
    $ownerForeignResponse = $this->withToken($ownerToken)->postJson(
        "/api/v1/conversion-connections/{$foreignConnection->id}/rotate",
        ['secret' => 'cross-workspace-must-not-rotate'],
        ['Idempotency-Key' => 'rest-connection-cross-workspace-owner'],
    );
    $ownerForeignResponse->assertNotFound();
});

it('durably replays session connection mutations and rejects key reuse with different input', function (): void {
    $owner = User::factory()->create();
    $payload = [
        'kind' => 'webhook',
        'display_name' => 'Session source',
        'allowed_source_types' => ['webhook'],
    ];

    $created = $this->actingAs($owner)->postJson(
        '/s2/conversion-connections',
        $payload,
        ['Idempotency-Key' => 'session-connection-create'],
    )->assertCreated();
    $connectionId = (string) $created->json('data.id');
    $issuedSecret = (string) $created->json('data.secret');

    expect($issuedSecret)->not->toBe('')
        ->and(WorkspaceClientOperation::query()
            ->where('initiating_user_id', $owner->id)
            ->where('client_operation_id', 'session-connection-create')
            ->count())->toBe(1);

    $replayed = $this->actingAs($owner)->postJson(
        '/s2/conversion-connections',
        $payload,
        ['Idempotency-Key' => 'session-connection-create'],
    )->assertCreated();
    expect($replayed->json('data.id'))->toBe($connectionId)
        ->and($replayed->json('data'))->not->toHaveKey('secret');

    $this->actingAs($owner)->postJson(
        '/s2/conversion-connections',
        [...$payload, 'display_name' => 'Different source'],
        ['Idempotency-Key' => 'session-connection-create'],
    )->assertStatus(409)->assertJsonPath('error.code', 'operation_replay_mismatch');

    $rotated = $this->actingAs($owner)->postJson(
        "/s2/conversion-connections/{$connectionId}/rotate",
        ['expected_lock_version' => 0],
        ['Idempotency-Key' => 'session-connection-rotate'],
    )->assertOk();
    expect($rotated->json('data.secret'))->not->toBe('');

    $replayedRotation = $this->actingAs($owner)->postJson(
        "/s2/conversion-connections/{$connectionId}/rotate",
        ['expected_lock_version' => 0],
        ['Idempotency-Key' => 'session-connection-rotate'],
    )->assertOk();
    expect($replayedRotation->json('data.id'))->toBe($connectionId)
        ->and($replayedRotation->json('data'))->not->toHaveKey('secret');

    $this->actingAs($owner)->postJson(
        "/s2/conversion-connections/{$connectionId}/rotate",
        ['expected_lock_version' => 1, 'display_name' => 'Different source'],
        ['Idempotency-Key' => 'session-connection-rotate'],
    )->assertStatus(409)->assertJsonPath('error.code', 'operation_replay_mismatch');

    $this->actingAs($owner)->postJson(
        "/s2/conversion-connections/{$connectionId}/disable",
        ['expected_lock_version' => 1],
        ['Idempotency-Key' => 'session-connection-disable'],
    )->assertOk()->assertJsonPath('data.status', 'disabled');
    $replayedDisable = $this->actingAs($owner)->postJson(
        "/s2/conversion-connections/{$connectionId}/disable",
        ['expected_lock_version' => 1],
        ['Idempotency-Key' => 'session-connection-disable'],
    )->assertOk();
    expect($replayedDisable->json('data.status'))->toBe('disabled')
        ->and($replayedDisable->json('data'))->not->toHaveKey('secret');
});

it('accepts only verified, workspace-allowed webhook events and deduplicates delivery', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $makeDefinition = static function (User $actor, string $eventKey, array $allowedSources = ['webhook']): ConversionDefinition {
        $definition = app(CreateConversionDefinition::class)->handle($actor->currentWorkspace(), $actor, [
            'event_key' => $eventKey,
            'display_name' => $eventKey,
            'description' => 'A signed Account conversion.',
            'subject_type' => 'Account',
            'allowed_sources' => $allowedSources,
            'property_schema' => [
                'type' => 'object',
                'properties' => [],
                'required' => [],
                'additionalProperties' => false,
            ],
            'allowed_identity_keys' => ['account_id'],
            'dedupe_strategy' => 'source_and_external_id',
            'dedupe_configuration' => [],
            'attribution_window' => ['days' => 30],
            'value_policy' => ['allowed' => false, 'currency' => null],
            'correction_policy' => ['allowed' => true],
            'reversal_policy' => ['allowed' => true],
            'retention_policy' => ['unmatched_identity_days' => 30],
            'export_policy' => ['allowed' => true],
        ]);

        return app(ActivateConversionDefinition::class)->handle($definition, $actor);
    };
    $definition = $makeDefinition($owner, 'signed_rest_acceptance');
    $unallowedDefinition = $makeDefinition($owner, 'signed_other_event');
    $foreignOwner = User::factory()->create();
    $foreignDefinition = $makeDefinition($foreignOwner, 'signed_foreign_event');
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Signed webhook Account',
        'website' => 'https://signed-webhook.example',
        'domain_normalized' => 'signed-webhook.example',
    ]);
    $token = apiToken($owner, [TokenScope::ConversionConnectionsWrite->value]);
    $connectionResponse = $this->withToken($token)->postJson(
        '/api/v1/conversion-connections',
        [
            'kind' => 'webhook',
            'secret' => 'webhook-first-secret',
            'display_name' => 'Signed REST source',
            'allowed_conversion_definition_ids' => [$definition->id],
            'allowed_source_types' => ['webhook'],
            'rotation_overlap_seconds' => 600,
        ],
        ['Idempotency-Key' => 'signed-connection-create'],
    )->assertCreated();
    $connectionId = (string) $connectionResponse->json('data.id');
    $publicId = (string) $connectionResponse->json('data.public_id');

    $send = function (array $payload, string $secret, ?int $timestamp = null) use ($publicId) {
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp ??= now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return $this->call(
            'POST',
            "/webhooks/v1/conversions/{$publicId}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            $rawBody,
        );
    };
    $timestamp = now()->timestamp;
    $firstPayload = [
        'definition_version_id' => $definition->current_version_id,
        'external_event_id' => 'signed-delivery-1',
        'properties' => [],
        'identities' => [['type' => 'account_id', 'value' => (string) $account->id]],
    ];

    $send($firstPayload, 'webhook-first-secret', $timestamp)
        ->assertAccepted()
        ->assertJsonPath('data.accepted', true)
        ->assertJsonMissingPath('data.receipt_id')
        ->assertJsonMissingPath('data.event');
    $send($firstPayload, 'webhook-first-secret', $timestamp)
        ->assertAccepted()
        ->assertJsonPath('data.accepted', true);

    expect(ConversionEvent::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(ConversionEventReceipt::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(ConversionEvent::query()->where('matched_account_id', $account->id)->count())->toBe(1);

    $send($firstPayload, 'wrong-secret', $timestamp)->assertUnauthorized();
    $send($firstPayload, 'webhook-first-secret', now()->subHour()->timestamp)->assertUnauthorized();

    $rotated = $this->withToken($token)->postJson(
        "/api/v1/conversion-connections/{$connectionId}/rotate",
        [
            'secret' => 'webhook-second-secret',
            'expected_lock_version' => 0,
            'rotation_overlap_seconds' => 600,
        ],
        ['Idempotency-Key' => 'signed-connection-rotate'],
    )->assertOk();
    expect($rotated->json('data.current_version.version'))->toBe(2);

    $oldSecretPayload = array_replace($firstPayload, ['external_event_id' => 'signed-old-overlap']);
    $send($oldSecretPayload, 'webhook-first-secret', $timestamp)->assertAccepted();
    $newSecretPayload = array_replace($firstPayload, ['external_event_id' => 'signed-new-version']);
    $send($newSecretPayload, 'webhook-second-secret')->assertAccepted();

    $send(array_replace($firstPayload, ['definition_id' => $unallowedDefinition->id]), 'webhook-second-secret')
        ->assertUnauthorized();
    $send(array_replace($firstPayload, ['definition_id' => $foreignDefinition->id]), 'webhook-second-secret')
        ->assertUnauthorized();

    $unknownResponse = $this->call(
        'POST',
        '/webhooks/v1/conversions/00000000-0000-4000-8000-000000000000',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        '{}',
    );
    $unknownResponse->assertUnauthorized();

    $this->withToken($token)->postJson(
        "/api/v1/conversion-connections/{$connectionId}/disable",
        ['expected_lock_version' => 1],
        ['Idempotency-Key' => 'signed-connection-disable'],
    )->assertOk();
    $disabledResponse = $send($newSecretPayload, 'webhook-second-secret');
    $disabledResponse->assertUnauthorized();
    expect($disabledResponse->getContent())->toBe($unknownResponse->getContent());

    expect(Campaign::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(CampaignRecipient::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(Message::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(Thread::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(SendAttempt::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(OutboxEvent::query()->count())->toBe(0);
});

it('enforces connection-specific body and request limits before webhook intake', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $definition = app(CreateConversionDefinition::class)->handle($workspace, $owner, [
        'event_key' => 'bounded_webhook',
        'display_name' => 'Bounded webhook',
        'description' => 'A bounded signed event.',
        'subject_type' => 'Account',
        'allowed_sources' => ['webhook'],
        'property_schema' => [
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'additionalProperties' => false,
        ],
        'allowed_identity_keys' => ['account_id'],
        'dedupe_strategy' => 'source_and_external_id',
        'dedupe_configuration' => [],
        'attribution_window' => ['days' => 30],
        'value_policy' => ['allowed' => false, 'currency' => null],
        'correction_policy' => ['allowed' => true],
        'reversal_policy' => ['allowed' => true],
        'retention_policy' => ['unmatched_identity_days' => 30],
        'export_policy' => ['allowed' => true],
    ]);
    $definition = app(ActivateConversionDefinition::class)->handle($definition, $owner);
    $token = apiToken($owner, [TokenScope::ConversionConnectionsWrite->value]);
    $connectionResponse = $this->withToken($token)->postJson(
        '/api/v1/conversion-connections',
        [
            'kind' => 'webhook',
            'secret' => 'bounded-secret',
            'allowed_conversion_definition_ids' => [$definition->id],
            'allowed_source_types' => ['webhook'],
            'payload_limit_bytes' => 1024,
            'rate_policy' => ['requests_per_minute' => 1],
        ],
        ['Idempotency-Key' => 'bounded-connection-create'],
    )->assertCreated();
    $publicId = (string) $connectionResponse->json('data.public_id');

    $send = function (string $rawBody) use ($publicId): TestResponse {
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, 'bounded-secret');

        return $this->call(
            'POST',
            "/webhooks/v1/conversions/{$publicId}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            $rawBody,
        );
    };
    $oversized = json_encode([
        'definition_id' => $definition->id,
        'external_event_id' => 'oversized-event',
        'properties' => [],
        'padding' => str_repeat('x', 1500),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $send($oversized)->assertStatus(413);
    $send(json_encode([
        'definition_id' => $definition->id,
        'external_event_id' => 'bounded-event',
        'properties' => [],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->assertStatus(429);

    expect(ConversionEvent::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
});
