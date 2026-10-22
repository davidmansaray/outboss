<?php

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\Enums\ConversionEventState;
use App\Domain\Conversions\Enums\ConversionReviewAction;
use App\Domain\Conversions\Exceptions\ConversionIdempotencyConflict;
use App\Domain\Conversions\Support\CanonicalConversionPayload;
use App\Domain\Conversions\Support\ConversionServiceSupport;
use App\Domain\Objectives\Services\DispatchObjectiveAttribution;
use App\Models\ConversionEvent;
use App\Models\ConversionEventReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Append an idempotent reversal successor for a reversible definition. */
final class ReverseConversionEvent
{
    use ConversionServiceSupport;

    public function __construct(
        private readonly RecordConversionEventReview $reviews,
        private readonly DispatchObjectiveAttribution $attribution,
    ) {}

    public function handle(ConversionEvent $event, User $actor, ?string $reason = null, ?string $idempotencyKey = null): ConversionEvent
    {
        $this->assertMember($event->workspace, $actor);
        $version = $event->definitionVersion()->firstOrFail();
        $policyAttribute = $version->getAttribute('reversal_policy');
        $policy = is_array($policyAttribute) ? $policyAttribute : [];
        if (($policy['allowed'] ?? true) !== true) {
            throw ValidationException::withMessages(['reversal' => 'This conversion definition does not allow reversals.']);
        }
        $dedupeKey = $idempotencyKey !== null && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : 'reversal';
        $sourceNamespace = 'reversal:'.$event->id;
        $dedupeHash = hash('sha256', $version->conversion_definition_id.'|'.$dedupeKey);
        $payloadHash = CanonicalConversionPayload::hash([
            'event_id' => $event->id,
            'reversal' => true,
            'key' => $dedupeKey,
        ]);

        $reversal = DB::transaction(function () use ($event, $actor, $version, $reason, $dedupeKey, $sourceNamespace, $dedupeHash, $payloadHash): ConversionEvent {
            $original = ConversionEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $priorState = ConversionEventState::tryFrom((string) $original->getRawOriginal('state')) ?? ConversionEventState::Received;
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
            if ($original->getRawOriginal('state') === ConversionEventState::Reversed->value) {
                throw new \RuntimeException('conversion_event_already_reversed');
            }
            $receipt = ConversionEventReceipt::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $original->workspace_id,
                'connection_id' => null,
                'connection_version_id' => null,
                'manual_actor_id' => $actor->id,
                'source_namespace' => $sourceNamespace,
                'source_type' => 'manual',
                'external_event_id' => $original->external_event_id === null ? null : $original->external_event_id.':reversal',
                'dedupe_key' => $dedupeKey,
                'dedupe_hash' => $dedupeHash,
                'payload_hash' => $payloadHash,
                'schema_version' => $version->schema_version,
                'received_at' => now(),
                'occurred_at' => now(),
                'verification_state' => 'not_required',
                'intake_state' => 'accepted',
                'safe_rejection_code' => null,
                'unmatched_identity_envelope' => null,
                'identity_expires_at' => null,
                'identity_redacted_at' => null,
                'conversion_event_id' => null,
                'actor_display_name' => $actor->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $successor = ConversionEvent::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $original->workspace_id,
                'conversion_definition_id' => $original->conversion_definition_id,
                'conversion_definition_version_id' => $original->conversion_definition_version_id,
                'receipt_id' => $receipt->id,
                'source_namespace' => $sourceNamespace,
                'source_type' => 'manual',
                'external_event_id' => $original->external_event_id === null ? null : $original->external_event_id.':reversal',
                'properties' => $original->properties ?? [],
                'identity_handles' => $original->identity_handles ?? [],
                'occurred_at' => now(),
                'received_at' => now(),
                'value' => $original->value,
                'currency' => $original->currency,
                'verification_state' => 'not_required',
                'state' => ConversionEventState::Reversed,
                'matched_account_id' => $original->matched_account_id,
                'lineage_root_id' => $original->lineage_root_id ?: $original->id,
                'corrects_event_id' => null,
                'reverses_event_id' => $original->id,
                'safe_rejection_code' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $receipt->project(['conversion_event_id' => $successor->id]);
            $original->project(['state' => ConversionEventState::Reversed]);
            $this->reviews->handle(
                $original->fresh(),
                ConversionReviewAction::Reverse,
                $actor,
                $reason,
                $successor,
                null,
                $priorState,
                ConversionEventState::Reversed,
            );

            return $successor->fresh();
        }, 3);
        $this->attribution->afterCommit((int) $reversal->workspace_id, (string) $reversal->id);

        return $reversal;
    }

    public function reverse(ConversionEvent $event, User $actor, ?string $reason = null, ?string $idempotencyKey = null): ConversionEvent
    {
        return $this->handle($event, $actor, $reason, $idempotencyKey);
    }
}
