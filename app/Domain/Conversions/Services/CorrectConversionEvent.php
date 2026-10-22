<?php

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\Enums\ConversionEventState;
use App\Domain\Conversions\Enums\ConversionReviewAction;
use App\Domain\Conversions\Exceptions\ConversionIdempotencyConflict;
use App\Domain\Conversions\Support\CanonicalConversionPayload;
use App\Domain\Conversions\Support\ConversionEventValidator;
use App\Domain\Conversions\Support\ConversionIdentity;
use App\Domain\Conversions\Support\ConversionServiceSupport;
use App\Domain\Objectives\Services\DispatchObjectiveAttribution;
use App\Models\ConversionDefinitionVersion;
use App\Models\ConversionEvent;
use App\Models\ConversionEventReceipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Append a corrected successor event while preserving the source event. */
final class CorrectConversionEvent
{
    use ConversionServiceSupport;

    public function __construct(
        private readonly ConversionEventValidator $validator,
        private readonly MatchConversionEvent $matcher,
        private readonly RecordConversionEventReview $reviews,
        private readonly DispatchObjectiveAttribution $attribution,
    ) {}

    /** @param array<string,mixed> $changes */
    public function handle(
        ConversionEvent $event,
        User $actor,
        array $changes,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): ConversionEvent {
        $this->assertMember($event->workspace, $actor);
        $version = $event->definitionVersion()->firstOrFail();
        $policyAttribute = $version->getAttribute('correction_policy');
        $policy = is_array($policyAttribute) ? $policyAttribute : [];
        if (($policy['allowed'] ?? true) !== true) {
            throw ValidationException::withMessages(['correction' => 'This conversion definition does not allow corrections.']);
        }

        $normalised = $this->normaliseSuccessorPayload($version, $event, $changes);
        $payloadForHash = [
            'event_id' => $event->id,
            'changes' => $changes,
            'normalised' => $normalised,
        ];
        $dedupeKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : CanonicalConversionPayload::hash($payloadForHash);
        $sourceNamespace = 'correction:'.$event->id;
        $dedupeHash = hash('sha256', $version->conversion_definition_id.'|'.$dedupeKey);
        $payloadHash = CanonicalConversionPayload::hash($payloadForHash);
        $receiptId = (string) Str::uuid();
        $successorId = (string) Str::uuid();
        $envelope = $normalised['raw_identities'] ?? null;
        $identityExpiry = $envelope === null ? null : CarbonImmutable::now()->addDays($this->identityDays($version));

        $corrected = DB::transaction(function () use (
            $event,
            $actor,
            $version,
            $normalised,
            $reason,
            $sourceNamespace,
            $dedupeHash,
            $payloadHash,
            $dedupeKey,
            $receiptId,
            $successorId,
            $envelope,
            $identityExpiry,
        ): ConversionEvent {
            $original = ConversionEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $this->assertMember($original->workspace, $actor);
            $existing = ConversionEventReceipt::query()
                ->where('workspace_id', $original->workspace_id)
                ->where('source_namespace', $sourceNamespace)
                ->where('dedupe_hash', $dedupeHash)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new ConversionIdempotencyConflict;
                }

                return $existing->event()->firstOrFail();
            }

            $receipt = ConversionEventReceipt::query()->create([
                'id' => $receiptId,
                'workspace_id' => $original->workspace_id,
                'connection_id' => null,
                'connection_version_id' => null,
                'manual_actor_id' => $actor->id,
                'source_namespace' => $sourceNamespace,
                'source_type' => 'manual',
                'external_event_id' => $normalised['external_event_id'],
                'dedupe_key' => mb_strlen($dedupeKey) > 255 ? substr($dedupeKey, 0, 255) : $dedupeKey,
                'dedupe_hash' => $dedupeHash,
                'payload_hash' => $payloadHash,
                'schema_version' => $version->schema_version,
                'received_at' => now(),
                'occurred_at' => $normalised['occurred_at'],
                'verification_state' => 'not_required',
                'intake_state' => 'accepted',
                'safe_rejection_code' => null,
                'unmatched_identity_envelope' => $envelope,
                'identity_expires_at' => $identityExpiry,
                'identity_redacted_at' => null,
                'conversion_event_id' => null,
                'actor_display_name' => $actor->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $successor = ConversionEvent::query()->create([
                'id' => $successorId,
                'workspace_id' => $original->workspace_id,
                'conversion_definition_id' => $version->conversion_definition_id,
                'conversion_definition_version_id' => $version->id,
                'receipt_id' => $receipt->id,
                'source_namespace' => $sourceNamespace,
                'source_type' => 'manual',
                'external_event_id' => $normalised['external_event_id'],
                'properties' => $normalised['properties'],
                'identity_handles' => $normalised['identity_handles'],
                'occurred_at' => $normalised['occurred_at'],
                'received_at' => now(),
                'value' => $normalised['value'],
                'currency' => $normalised['currency'],
                'verification_state' => 'not_required',
                'state' => 'received',
                'matched_account_id' => null,
                'lineage_root_id' => $original->lineage_root_id ?: $original->id,
                'corrects_event_id' => $original->id,
                'reverses_event_id' => null,
                'safe_rejection_code' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $receipt->project(['conversion_event_id' => $successor->id]);
            $priorState = ConversionEventState::tryFrom((string) $original->getRawOriginal('state')) ?? ConversionEventState::Received;
            $original->project(['state' => ConversionEventState::Corrected]);
            $matched = $this->matcher->handle($successor, $actor, reason: $reason);
            $this->reviews->handle(
                $original->fresh(),
                ConversionReviewAction::Correct,
                $actor,
                $reason,
                $successor,
                $matched->match,
                $priorState,
                ConversionEventState::tryFrom((string) $matched->event->getRawOriginal('state')) ?? ConversionEventState::Received,
            );

            return $matched->event->fresh();
        }, 3);
        $this->attribution->afterCommit((int) $corrected->workspace_id, (string) $corrected->id);

        return $corrected;
    }

    /** @param array<string,mixed> $changes */
    public function correct(ConversionEvent $event, User $actor, array $changes, ?string $reason = null, ?string $idempotencyKey = null): ConversionEvent
    {
        return $this->handle($event, $actor, $changes, $reason, $idempotencyKey);
    }

    /**
     * @param  array<string,mixed>  $changes
     * @return array<string,mixed>
     */
    private function normaliseSuccessorPayload(ConversionDefinitionVersion $version, ConversionEvent $event, array $changes): array
    {
        $hasIdentityChange = array_key_exists('identities', $changes)
            || array_key_exists('account_id', $changes)
            || array_key_exists('domain', $changes)
            || array_key_exists('external_identity', $changes);
        $occurredAt = $event->getAttribute('occurred_at');
        $properties = $event->getAttribute('properties');
        $base = [
            'external_event_id' => $event->external_event_id,
            'occurred_at' => $occurredAt instanceof DateTimeInterface ? $occurredAt->format(DateTimeInterface::ATOM) : null,
            'properties' => is_array($properties) ? $properties : [],
            'value' => $event->getAttribute('value'),
            'currency' => $event->getAttribute('currency'),
        ];
        if ($hasIdentityChange) {
            $input = array_replace($base, $changes);
            $normalised = $this->validator->validate($version, $input);
            $this->assertAllowedIdentities($version, $normalised['identities']);

            return [
                ...$normalised,
                'identity_handles' => array_map(static fn (array $identity): array => ConversionIdentity::safeHandle($identity), $normalised['identities']),
                'raw_identities' => $normalised['identities'],
            ];
        }

        $input = array_replace($base, $changes);
        $normalised = $this->validator->validate($version, $input);

        return [
            ...$normalised,
            'identity_handles' => is_array($event->getAttribute('identity_handles')) ? $event->getAttribute('identity_handles') : [],
            'raw_identities' => null,
        ];
    }

    /** @param list<array<string,mixed>> $identities */
    private function assertAllowedIdentities(ConversionDefinitionVersion $version, array $identities): void
    {
        $allowed = array_map('strval', (array) $version->allowed_identity_keys);
        foreach ($identities as $identity) {
            if (! in_array((string) ($identity['type'] ?? ''), $allowed, true)) {
                throw ValidationException::withMessages(['identities' => 'An identity type is not allowed by this definition.']);
            }
        }
    }

    private function identityDays(ConversionDefinitionVersion $version): int
    {
        $policyAttribute = $version->getAttribute('retention_policy');
        $policy = is_array($policyAttribute) ? $policyAttribute : [];

        return max(0, min(30, (int) ($policy['unmatched_identity_days'] ?? 30)));
    }
}
