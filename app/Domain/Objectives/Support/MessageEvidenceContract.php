<?php

namespace App\Domain\Objectives\Support;

use App\Domain\Intelligence\Enums\ResearchClaimEvidenceEdgeType;
use App\Domain\Intelligence\Enums\ResearchClaimStatus;
use App\Domain\Intelligence\Support\CanonicalIntelligence;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvidence;

/**
 * Builds and re-verifies the minimum frozen Claim data that may be used in an
 * outbound message. Raw source material and provider payloads never cross this
 * boundary.
 */
final class MessageEvidenceContract
{
    public const VERSION = 'message_evidence_contract_v1';

    /**
     * Collapse an exact per-Account Claim into a non-identifying arm choice.
     * The provider sees this opaque ID and value type, never the Claim ID or
     * value from which it was derived.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function choiceId(array $manifest): ?string
    {
        $predicate = $manifest['predicate'] ?? null;
        $valueType = $manifest['value_type'] ?? null;
        if (! is_string($predicate) || trim($predicate) === '' || mb_strlen($predicate) > 160
            || ! is_string($valueType)
            || ! in_array($valueType, ['string', 'date', 'integer', 'number', 'boolean', 'string_list'], true)) {
            return null;
        }

        return 'evidence_v1_'.CanonicalObjectivePayload::hash([
            'predicate' => trim($predicate),
            'value_type' => $valueType,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentManifest(ResearchClaim $claim, int $workspaceId, int $accountId): ?array
    {
        // Source snapshots can expire or be redacted after the Claim was
        // loaded. Always reload this mutable usability boundary before
        // freezing or re-verifying outbound evidence.
        $claim->load('evidence.sourceSnapshot');

        $freshUntil = ModelAttribute::instant($claim->fresh_until);
        $expiresAt = ModelAttribute::instant($claim->expires_at);
        $observedAt = ModelAttribute::instant($claim->observed_at);
        if ((int) $claim->workspace_id !== $workspaceId
            || (int) $claim->account_id !== $accountId
            || $claim->subject_type !== 'account'
            || ! in_array($claim->status, [ResearchClaimStatus::Supported, ResearchClaimStatus::OwnerConfirmed], true)
            || ! (bool) $claim->safe_for_message
            || ! hash_equals((string) $claim->value_hash, CanonicalIntelligence::valueHash($claim->value_json))
            || $freshUntil === null
            || ! $freshUntil->isFuture()
            || ($expiresAt !== null && ! $expiresAt->isFuture())
            || ResearchClaim::query()->where('workspace_id', $workspaceId)
                ->where('account_id', $accountId)
                ->where('supersedes_claim_id', $claim->id)
                ->exists()
            || $this->prohibited((string) $claim->predicate, $claim->value_json)) {
            return null;
        }

        $supportingEvidence = $claim->evidence
            ->filter(fn (ResearchClaimEvidence $edge): bool => ModelAttribute::enumValue($edge->edge_type) === ResearchClaimEvidenceEdgeType::Supports->value
                && $this->edgeUsable($edge))
            ->map(fn ($edge): array => [
                'evidence_id' => (string) $edge->id,
                'source_snapshot_id' => (string) $edge->source_snapshot_id,
                'locator_hash' => (string) $edge->locator_hash,
                'quote_hash' => $edge->quote_hash === null ? null : (string) $edge->quote_hash,
            ])
            ->sortBy('evidence_id')
            ->values()
            ->all();
        if ($claim->status === ResearchClaimStatus::Supported && $supportingEvidence === []) {
            return null;
        }

        $messageValue = $this->messageValue((string) $claim->value_type, $claim->value_json);
        if ($messageValue === null) {
            return null;
        }

        $manifest = [
            'contract_version' => self::VERSION,
            'claim_id' => (string) $claim->id,
            'workspace_id' => $workspaceId,
            'account_id' => $accountId,
            'content_hash' => (string) $claim->content_hash,
            'value_hash' => (string) $claim->value_hash,
            'predicate' => (string) $claim->predicate,
            'value_type' => (string) $claim->value_type,
            'message_value' => $messageValue,
            'status' => $claim->status->value,
            'support_kind' => $claim->status === ResearchClaimStatus::OwnerConfirmed
                ? 'owner_confirmed'
                : 'evidence_backed',
            'supporting_evidence' => $supportingEvidence,
            'safe_for_message' => true,
            'prohibited' => false,
            'current' => true,
            'nonsuperseded' => true,
            'observed_at' => $observedAt?->toAtomString(),
            'fresh_until' => $freshUntil->toAtomString(),
            'expires_at' => $expiresAt?->toAtomString(),
        ];

        return [
            ...$manifest,
            'safety_hash' => CanonicalObjectivePayload::hash($manifest),
        ];
    }

    /**
     * Re-query a Claim and prove that its live safe representation is exactly
     * the representation frozen in an Audience Snapshot.
     *
     * @param  array<string, mixed>  $frozen
     * @return array<string, mixed>|null
     */
    public function verifyFrozen(array $frozen, int $workspaceId, int $accountId): ?array
    {
        $claimId = $frozen['claim_id'] ?? null;
        if (! is_string($claimId) || $claimId === '') {
            return null;
        }
        $claim = ResearchClaim::query()
            ->where('workspace_id', $workspaceId)
            ->where('account_id', $accountId)
            ->whereKey($claimId)
            ->with('evidence.sourceSnapshot')
            ->first();
        if ($claim === null) {
            return null;
        }
        $current = $this->currentManifest($claim, $workspaceId, $accountId);
        if ($current === null
            || ! hash_equals(
                CanonicalObjectivePayload::hash($current),
                CanonicalObjectivePayload::hash($frozen),
            )) {
            return null;
        }

        return $current;
    }

    public function prohibited(string $predicate, mixed $value): bool
    {
        if (preg_match('/(?:^|[._-])(race|religion|ethnicity|politic|gender|sexual|health|medical|disability|age|protected)(?:$|[._-])/i', $predicate) === 1
            || preg_match('/(?:^|[._-])(competitor|competition|comparison)(?:$|[._-])/i', $predicate) === 1) {
            return true;
        }

        $encoded = is_scalar($value) || $value === null
            ? (string) $value
            : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            return true;
        }

        foreach ([
            '/\b(race|religion|ethnicity|sexual orientation|medical condition|disability|political affiliation|protected class)\b/i',
            '/\b(competitor|competition|versus|vs\.?|better than|worse than|outperform(?:s|ed|ing)?)\b/i',
            '/\b(guarantee(?:d|s)?|risk[- ]free|unlimited capacity|limited capacity|guaranteed availability|only \d+ (?:slots|spots)|capacity of \d+|will (?:increase|decrease|deliver|ensure|produce))\b/i',
            '/\b(because of|cause(?:d|s)?|led to|results? in|therefore|which means)\b/i',
            '/\b(i noticed you|i saw you|we met before|as you know me|our previous conversation|our records show)\b/i',
        ] as $pattern) {
            if (preg_match($pattern, $encoded) === 1) {
                return true;
            }
        }

        return false;
    }

    private function messageValue(string $valueType, mixed $value): ?string
    {
        $rendered = match ($valueType) {
            'string', 'date' => is_string($value) && hash_equals($value, trim($value)) ? $value : null,
            'integer' => is_int($value) ? (string) $value : null,
            'number' => is_int($value) || is_float($value)
                ? json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)
                : null,
            'boolean' => is_bool($value) ? ($value ? 'true' : 'false') : null,
            'string_list' => is_array($value) && array_is_list($value)
                && array_filter($value, 'is_string') === $value
                && collect($value)->every(static fn (string $item): bool => $item !== '' && hash_equals($item, trim($item)))
                    ? implode(', ', $value)
                    : null,
            default => null,
        };
        if (! is_string($rendered) || $rendered === '' || mb_strlen($rendered) > 500
            || preg_match('/\p{Cc}|[\x{0085}\x{2028}\x{2029}]/u', $rendered) !== 0
            || str_contains($rendered, '{{') || str_contains($rendered, '}}')
            || preg_match('~(?:(?:https?|ftp)://|(?:mailto|data|javascript):|www\.)~iu', $rendered) === 1) {
            return null;
        }

        return $rendered;
    }

    private function edgeUsable(ResearchClaimEvidence $edge): bool
    {
        $snapshot = $edge->sourceSnapshot;
        if ($snapshot === null
            || ! in_array(ModelAttribute::enumValue($snapshot->state), ['available', 'partial'], true)) {
            return false;
        }
        if (ModelAttribute::instant($snapshot->expires_at)?->isPast() === true) {
            return false;
        }

        return $snapshot->redacted_at === null;
    }
}
