<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AccountResearchRun;
use Illuminate\Http\Request;

class AccountResearchRunResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $run = $this->model(AccountResearchRun::class);
        $costs = $run->costProjection();

        $payload = [
            'id' => (string) $run->id,
            'account_id' => (int) $run->account_id,
            'workspace_id' => (int) $run->workspace_id,
            'gtm_profile_version_id' => $run->gtm_profile_version_id === null ? null : (string) $run->gtm_profile_version_id,
            'offer_version_id' => $run->offer_version_id === null ? null : (string) $run->offer_version_id,
            'parent_run_id' => $run->parent_run_id === null ? null : (string) $run->parent_run_id,
            'state' => $this->enumValue($run->getAttribute('state')) ?? (string) $run->getRawOriginal('state'),
            'awaiting_reason' => $run->awaiting_reason,
            'safe_failure_code' => $run->safe_failure_code,
            'current_stage' => $run->current_stage,
            'account_snapshot' => $this->safeAccountSnapshot($run->getAttribute('account_snapshot')),
            'freshness_policy' => is_array($run->getAttribute('freshness_policy')) ? $run->getAttribute('freshness_policy') : [],
            'capability_snapshot' => $this->safeCapabilitySnapshot($run->getAttribute('capability_snapshot')),
            'input_hash' => (string) $run->input_hash,
            'estimated_cost_micros' => (int) $run->estimated_cost_micros,
            'reserved_cost_micros' => $costs['reserved_cost_micros'],
            'actual_cost_micros' => $costs['actual_cost_micros'],
            'maximum_cost_micros' => (int) $run->maximum_cost_micros,
            'currency' => (string) $run->currency,
            'orchestration_version' => (int) $run->orchestration_version,
            'queued_at' => $this->iso8601($run->getAttribute('queued_at')),
            'started_at' => $this->iso8601($run->getAttribute('started_at')),
            'finished_at' => $this->iso8601($run->getAttribute('finished_at')),
            'cancelled_at' => $this->iso8601($run->getAttribute('cancelled_at')),
        ];

        if ($run->relationLoaded('stages')) {
            $payload['stages'] = AccountResearchStageResource::collection($run->stages)->resolve();
        }
        if ($run->relationLoaded('attempts')) {
            $payload['attempts'] = AccountResearchAttemptResource::collection($run->attempts)->resolve();
        }
        if ($run->relationLoaded('snapshots')) {
            $payload['snapshots'] = ResearchSourceSnapshotResource::collection($run->snapshots)->resolve();
        }
        if ($run->relationLoaded('claims')) {
            $payload['claims'] = ResearchClaimResource::collection($run->claims)->resolve();
        }
        if ($run->relationLoaded('qualificationDecisions')) {
            $payload['qualification_decisions'] = QualificationDecisionResource::collection($run->qualificationDecisions)->resolve();
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    private function safeAccountSnapshot(mixed $snapshot): array
    {
        if (! is_array($snapshot)) {
            return [];
        }

        return array_intersect_key($snapshot, array_flip([
            'id', 'name', 'website', 'domain_normalized', 'tags', 'source',
        ]));
    }

    /** @return array<string,mixed> */
    private function safeCapabilitySnapshot(mixed $snapshot): array
    {
        if (! is_array($snapshot)) {
            return [];
        }

        return [
            'selected' => is_array($snapshot['selected'] ?? null) ? array_values(array_slice($snapshot['selected'], 0, 10)) : [],
            'required' => is_array($snapshot['required'] ?? null) ? $snapshot['required'] : [],
            'providers' => is_array($snapshot['providers'] ?? null)
                ? array_map(static function (mixed $provider): array {
                    if (! is_array($provider)) {
                        return [];
                    }

                    return [
                        'ready' => (bool) ($provider['ready'] ?? false),
                        'credential_status' => is_string($provider['credential_status'] ?? null)
                            ? $provider['credential_status']
                            : 'unknown',
                    ];
                }, $snapshot['providers'])
                : [],
            'policy_version' => $snapshot['policy_version'] ?? null,
        ];
    }
}
