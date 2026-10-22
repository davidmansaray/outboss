<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\Enums\ResearchClaimEventAction;
use App\Domain\Intelligence\Enums\ResearchClaimStatus;
use App\Domain\Intelligence\Exceptions\ClaimValidationException;
use App\Domain\Intelligence\Support\CanonicalIntelligence;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Append an owner correction; the original Claim and evidence remain intact. */
final class CorrectResearchClaim
{
    public function handle(
        Workspace $workspace,
        ResearchClaim|string $claim,
        User $actor,
        mixed $newValue,
        ?string $reason = null,
        ?string $valueType = null,
    ): ResearchClaim {
        if (! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can correct research Claims.');
        }
        $claimId = $claim instanceof ResearchClaim ? (string) $claim->id : $claim;

        return DB::transaction(function () use ($workspace, $claimId, $actor, $newValue, $reason, $valueType): ResearchClaim {
            $original = ResearchClaim::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($claimId)
                ->lockForUpdate()
                ->firstOrFail();
            $resolvedType = $valueType ?? $this->valueType($newValue);
            $this->validateValue($resolvedType, $newValue);
            $contentHash = CanonicalIntelligence::hash([
                'supersedes_claim_id' => (string) $original->id,
                'predicate' => $original->predicate,
                'value_type' => $resolvedType,
                'value' => $newValue,
                'status' => ResearchClaimStatus::OwnerConfirmed->value,
            ]);
            $successor = ResearchClaim::query()->where('workspace_id', $workspace->id)->where('content_hash', $contentHash)->first();
            if ($successor === null) {
                $successor = ResearchClaim::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'account_id' => $original->account_id,
                    'research_run_id' => $original->research_run_id,
                    'supersedes_claim_id' => $original->id,
                    'subject_type' => 'account',
                    'predicate' => $original->predicate,
                    'value_type' => $resolvedType,
                    'value_json' => $newValue,
                    'value_hash' => CanonicalIntelligence::valueHash($newValue),
                    'status' => ResearchClaimStatus::OwnerConfirmed,
                    'confidence' => 1,
                    'calibration_band' => 'owner_confirmed',
                    'observed_at' => now(),
                    'fresh_until' => null,
                    'expires_at' => null,
                    'extractor_kind' => 'owner_correction',
                    'extractor_version' => '1',
                    'prompt_version' => null,
                    'model_version' => null,
                    'policy_version' => null,
                    'catalogue_version' => null,
                    'safe_for_message' => false,
                    'content_hash' => $contentHash,
                ]);
            }
            ResearchClaimEvent::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'account_id' => $original->account_id,
                'claim_id' => $original->id,
                'action' => ResearchClaimEventAction::Correct,
                'actor_snapshot' => $this->actorSnapshot($actor),
                'actor_user_id' => $actor->id,
                'reason' => $reason,
                'successor_claim_id' => $successor->id,
                'prior_status' => ModelAttribute::enumValue($original->status),
                'projected_status' => ResearchClaimStatus::OwnerConfirmed->value,
                'created_at' => now(),
            ]);

            return $successor->fresh();
        }, 3);
    }

    public function correct(
        Workspace $workspace,
        ResearchClaim|string $claim,
        User $actor,
        mixed $newValue,
        ?string $reason = null,
        ?string $valueType = null,
    ): ResearchClaim {
        return $this->handle($workspace, $claim, $actor, $newValue, $reason, $valueType);
    }

    private function valueType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_array($value) && array_is_list($value) => 'string_list',
            is_array($value) => 'object',
            default => 'string',
        };
    }

    private function validateValue(string $type, mixed $value): void
    {
        $valid = match ($type) {
            'string', 'url', 'date' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'string_list' => is_array($value) && array_is_list($value) && array_filter($value, 'is_string') === $value,
            'object' => is_array($value) && ! array_is_list($value),
            default => false,
        };
        if (! $valid || strlen(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 16_384) {
            throw new ClaimValidationException('The owner correction value is not valid for the Claim type.');
        }
    }

    /** @return array<string,mixed> */
    private function actorSnapshot(User $actor): array
    {
        return ['user_id' => (int) $actor->id, 'display_name' => mb_substr((string) $actor->name, 0, 160)];
    }
}
