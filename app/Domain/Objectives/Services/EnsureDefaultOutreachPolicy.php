<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\OutreachPolicyStatus;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\OutreachPolicy;
use App\Models\OutreachPolicyVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final class EnsureDefaultOutreachPolicy
{
    public function handle(Workspace $workspace, ?User $actor = null): OutreachPolicy
    {
        return DB::transaction(function () use ($workspace, $actor): OutreachPolicy {
            $policy = OutreachPolicy::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->first();
            if ($policy?->current_version_id !== null) {
                return $policy;
            }
            $policy ??= OutreachPolicy::query()->create([
                'workspace_id' => $workspace->id,
                'status' => OutreachPolicyStatus::Active,
                'lock_version' => 1,
                'created_by_user_id' => $actor?->id,
            ]);

            $values = $this->defaults();
            $version = OutreachPolicyVersion::query()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'outreach_policy_id' => $policy->id,
                    'version' => 1,
                ],
                [...$values, 'content_hash' => CanonicalObjectivePayload::hash($values), 'created_by_user_id' => $actor?->id],
            );
            $policy->forceFill(['current_version_id' => $version->id])->save();

            return $policy->fresh('currentVersion');
        }, 3);
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        $safety = (array) config('objectives.safety');

        return [
            'maximum_people' => min(25, (int) $safety['maximum_people']),
            'maximum_accounts' => min(25, (int) $safety['maximum_accounts']),
            'maximum_messages' => min(25, (int) $safety['maximum_messages']),
            'maximum_ai_cost_micros' => min(1_000_000, (int) $safety['maximum_ai_cost_micros']),
            'approval_ttl_hours' => min(24, (int) $safety['approval_ttl_hours']),
            'send_window' => ['days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00', 'timezone_source' => 'workspace'],
            'allowed_source_purposes' => ['prospecting'],
            'qualification_constraints' => ['allowed_outcomes' => ['contact'], 'minimum_confidence' => 0.8],
            'evidence_constraints' => ['maximum_age_days' => 30, 'require_safe_reason_to_contact' => true],
            'offer_constraints' => ['require_approved_version' => true, 'require_current_readiness' => true],
            'fulfilment_constraints' => ['require_available_capacity' => true],
            'schema_version' => 'outreach_policy_v1',
        ];
    }
}
