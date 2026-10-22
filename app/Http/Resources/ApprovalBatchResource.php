<?php

namespace App\Http\Resources;

use App\Models\ApprovalBatch;
use App\Models\ApprovalGrant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApprovalBatch */
class ApprovalBatchResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'execution_plan_id' => (string) $this->execution_plan_id,
            'plan_hash' => (string) $this->plan_hash,
            'display_manifest_hash' => (string) $this->display_manifest_hash,
            'state' => $this->state->value,
            'action_count' => (int) $this->action_count,
            'decision_by_user_id' => $this->decision_by_user_id === null ? null : (int) $this->decision_by_user_id,
            'decided_at' => $this->decided_at?->toAtomString(),
            'expires_at' => $this->expires_at?->toAtomString(),
            'reason_code' => $this->reason_code,
            'reason_detail' => $this->reason_detail,
            'grants' => $this->whenLoaded('grants', fn () => $this->grants->map(
                fn (ApprovalGrant $grant): array => [
                    'id' => (string) $grant->id,
                    'action_id' => (string) $grant->execution_plan_action_id,
                    'action_fingerprint' => (string) $grant->action_fingerprint,
                    'policy_version_id' => (string) $grant->outreach_policy_version_id,
                    'policy_input_hash' => (string) $grant->policy_input_hash,
                    'decision' => $grant->decision->value,
                    'expires_at' => $grant->expires_at?->toAtomString(),
                ],
            )->values()->all()),
        ];
    }
}
