<?php

use App\Domain\Conversions\Enums\ConversionDefinitionStatus;
use App\Domain\Conversions\Exceptions\ConversionIdempotencyConflict;
use App\Domain\Conversions\Services\ActivateConversionDefinition;
use App\Domain\Conversions\Services\ConversionSourceConnectionManager;
use App\Domain\Conversions\Services\CopyConversionDefinitionTemplates;
use App\Domain\Conversions\Services\CorrectConversionEvent;
use App\Domain\Conversions\Services\CreateConversionDefinition;
use App\Domain\Conversions\Services\ExpireUnmatchedConversionIdentity;
use App\Domain\Conversions\Services\IngestConversionEvent;
use App\Domain\Conversions\Services\ReverseConversionEvent;
use App\Domain\Conversions\Services\VerifyConversionWebhook;
use App\Models\Account;
use App\Models\ConversionDefinition;
use App\Models\ConversionEvent;
use App\Models\ConversionEventMatch;
use App\Models\ConversionEventReviewEvent;
use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function conversionDefinitionAttributes(array $overrides = []): array
{
    return array_replace([
        'event_key' => 'demo_booked',
        'display_name' => 'Demo booked',
        'description' => 'A demo was booked.',
        'subject_type' => 'Account',
        'allowed_sources' => ['manual', 'api', 'webhook'],
        'property_schema' => [
            'type' => 'object',
            'properties' => ['plan' => ['type' => 'string']],
            'required' => [],
            'additionalProperties' => false,
        ],
        'allowed_identity_keys' => ['account_id', 'external_identity', 'domain'],
        'dedupe_strategy' => 'source_and_external_id',
        'dedupe_configuration' => [],
        'attribution_window' => ['days' => 30],
        'value_policy' => ['allowed' => false, 'currency' => null],
        'correction_policy' => ['allowed' => true],
        'reversal_policy' => ['allowed' => true],
        'retention_policy' => ['unmatched_identity_days' => 30],
        'export_policy' => ['allowed' => true],
    ], $overrides);
}

function createConversionDefinitionFor(User $owner, array $overrides = []): ConversionDefinition
{
    $definition = app(CreateConversionDefinition::class)->handle(
        $owner->currentWorkspace(),
        $owner,
        conversionDefinitionAttributes($overrides),
    );

    return app(ActivateConversionDefinition::class)->handle($definition, $owner);
}

it('copies editable versioned templates idempotently', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $first = app(CopyConversionDefinitionTemplates::class)->handle($workspace, $owner);
    $second = app(CopyConversionDefinitionTemplates::class)->handle($workspace, $owner);

    expect($first)->toHaveCount(4)
        ->and($second)->toHaveCount(4)
        ->and(ConversionDefinition::query()->where('workspace_id', $workspace->id)->count())->toBe(4)
        ->and(ConversionDefinition::query()->where('workspace_id', $workspace->id)->where('status', ConversionDefinitionStatus::Active)->count())->toBe(4)
        ->and($first->first()->fresh()->versions()->count())->toBe(1);
});

it('creates immutable definitions and versions and only activates Account definitions', function () {
    $owner = User::factory()->create();
    $definition = createConversionDefinitionFor($owner);
    $version = $definition->currentVersion;

    expect($definition->status)->toBe(ConversionDefinitionStatus::Active)
        ->and($version->version)->toBe(1)
        ->and(fn () => $version->forceFill(['display_name' => 'changed'])->save())->toThrow(LogicException::class);
});

it('deduplicates before matching side effects and matches exact Account identities', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'domain_normalized' => 'acme.example',
        'website' => 'https://acme.example',
    ]);
    $definition = createConversionDefinitionFor($owner);
    $payload = [
        'external_event_id' => 'evt-1',
        'occurred_at' => now()->subMinute()->toIso8601String(),
        'properties' => ['plan' => 'starter'],
        'identities' => [['type' => 'account_id', 'value' => $account->id]],
    ];
    $first = app(IngestConversionEvent::class)->handle($workspace, $definition, $payload, 'manual', null, $owner);
    $second = app(IngestConversionEvent::class)->handle($workspace, $definition, $payload, 'manual', null, $owner);

    expect($first->duplicate)->toBeFalse()
        ->and($first->event?->state->value)->toBe('matched')
        ->and($first->event?->matched_account_id)->toBe($account->id)
        ->and($first->event?->attribution_state)->toBe('not_evaluated')
        ->and($second->duplicate)->toBeTrue()
        ->and(ConversionEvent::query()->count())->toBe(1)
        ->and(ConversionEventMatch::query()->count())->toBe(1)
        ->and(fn () => app(IngestConversionEvent::class)->handle($workspace, $definition, array_replace($payload, ['properties' => ['plan' => 'pro']]), 'manual', null, $owner))->toThrow(ConversionIdempotencyConflict::class);
});

it('matches exact domains and external identities only within the workspace', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $account = Account::factory()->create(['workspace_id' => $workspace->id, 'domain_normalized' => 'domain.example']);
    $otherAccount = Account::factory()->create(['workspace_id' => $other->currentWorkspace()->id, 'domain_normalized' => 'other.example']);
    ExternalIdentity::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'entity_type' => 'account',
        'account_id' => $account->id,
        'person_id' => null,
        'provider' => 'crm',
        'identity_type' => 'account_id',
        'value' => 'crm-1',
        'value_hash' => hash('sha256', 'crm-1'),
        'state' => 'active',
        'retain_until' => now()->addYear(),
    ]);
    $definition = createConversionDefinitionFor($owner);
    $byDomain = app(IngestConversionEvent::class)->handle($workspace, $definition, [
        'external_event_id' => 'domain-event',
        'properties' => [],
        'identities' => [['type' => 'domain', 'value' => 'https://www.domain.example/path']],
    ], 'manual', null, $owner);
    $byExternal = app(IngestConversionEvent::class)->handle($workspace, $definition, [
        'external_event_id' => 'external-event',
        'properties' => [],
        'identities' => [['type' => 'external_identity', 'provider' => 'crm', 'identity_type' => 'account_id', 'value' => 'crm-1']],
    ], 'manual', null, $owner);
    $crossWorkspace = app(IngestConversionEvent::class)->handle($workspace, $definition, [
        'external_event_id' => 'cross-event',
        'properties' => [],
        'identities' => [['type' => 'account_id', 'value' => $otherAccount->id]],
    ], 'manual', null, $owner);

    expect($byDomain->event?->matched_account_id)->toBe($account->id)
        ->and($byExternal->event?->matched_account_id)->toBe($account->id)
        ->and($crossWorkspace->event?->state->value)->toBe('rejected')
        ->and($crossWorkspace->event?->matched_account_id)->toBeNull();
});

it('verifies timestamped webhook signatures and supports bounded rotation overlap', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $definition = createConversionDefinitionFor($owner);
    $connection = app(ConversionSourceConnectionManager::class)->connect($workspace, 'webhook', 'first-secret', $owner, [
        'allowed_conversion_definition_ids' => [$definition->id],
    ]);
    $body = json_encode(['external_event_id' => 'signed-1', 'properties' => [], 'identities' => []], JSON_THROW_ON_ERROR);
    $timestamp = now()->timestamp;
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'first-secret');

    expect(app(VerifyConversionWebhook::class)->verify($connection, $body, "t={$timestamp},v1={$signature}"))->toBeTrue();

    $rotated = app(ConversionSourceConnectionManager::class)->rotate($connection, 'second-secret', $owner, [
        'rotation_overlap_seconds' => 600,
    ]);
    expect(app(VerifyConversionWebhook::class)->verify($rotated, $body, "t={$timestamp},v1={$signature}"))->toBeTrue()
        ->and(DB::table('conversion_source_connection_versions')->where('id', $connection->current_version_id)->value('encrypted_secret'))->not->toBe('first-secret');
});

it('preserves correction and reversal lineage and expires unmatched identity envelopes', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $definition = createConversionDefinitionFor($owner);
    $unmatched = app(IngestConversionEvent::class)->handle($workspace, $definition, [
        'external_event_id' => 'unmatched-1',
        'properties' => [],
        'identities' => [['type' => 'domain', 'value' => 'unknown.example']],
    ], 'manual', null, $owner);
    $receipt = $unmatched->receipt->fresh();
    expect($unmatched->event?->state->value)->toBe('unmatched')
        ->and($receipt->unmatched_identity_envelope)->not->toBeNull();
    $receipt->project(['identity_expires_at' => now()->subSecond()]);
    expect(app(ExpireUnmatchedConversionIdentity::class)->handle($workspace))->toBe(1)
        ->and($receipt->fresh()->unmatched_identity_envelope)->toBeNull();

    $account = Account::factory()->create(['workspace_id' => $workspace->id, 'domain_normalized' => 'known.example']);
    $matched = app(IngestConversionEvent::class)->handle($workspace, $definition, [
        'external_event_id' => 'lineage-1',
        'properties' => [],
        'identities' => [['type' => 'domain', 'value' => 'unknown.example']],
    ], 'manual', null, $owner);
    $corrected = app(CorrectConversionEvent::class)->handle($matched->event, $owner, [
        'identities' => [['type' => 'domain', 'value' => 'known.example']],
    ], 'operator correction', 'correction-1');
    $reversed = app(ReverseConversionEvent::class)->handle($corrected, $owner, 'operator reversal');

    expect($matched->event->fresh()->state->value)->toBe('corrected')
        ->and($corrected->state->value)->toBe('matched')
        ->and($corrected->matched_account_id)->toBe($account->id)
        ->and($corrected->corrects_event_id)->toBe($matched->event->id)
        ->and($reversed->state->value)->toBe('reversed')
        ->and($reversed->reverses_event_id)->toBe($corrected->id)
        ->and(ConversionEventReviewEvent::query()->where('action', 'correct')->count())->toBe(1)
        ->and(ConversionEventReviewEvent::query()->where('action', 'reverse')->count())->toBe(1);
});
