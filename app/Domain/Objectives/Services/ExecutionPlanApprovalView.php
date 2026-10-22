<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\ApprovalBatchState;
use App\Domain\Objectives\Enums\ApprovalGrantDecision;
use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use App\Domain\Objectives\Support\ApprovalManifest;
use App\Models\ApprovalBatch;
use App\Models\ApprovalGrant;
use App\Models\ExecutionPlan;
use Throwable;

final class ExecutionPlanApprovalView
{
    public function __construct(
        private readonly ApprovalManifest $manifest,
        private readonly ExactExecutionPlanValidator $validator,
    ) {}

    /** @return array<string,mixed> */
    public function handle(ExecutionPlan $plan, bool $owner): array
    {
        $actions = $plan->actions()->orderBy('id')->get();
        $blockers = [];
        $warnings = [];
        $inputHashes = [];
        try {
            $validation = $this->validator->handle($plan, false);
            $blockers = $validation->blockers;
            $warnings = $validation->warnings;
            $inputHashes = $validation->actionInputHashes;
        } catch (Throwable) {
            $blockers[] = [
                'code' => 'approval_dependencies_unavailable',
                'message' => 'The exact approval dependencies could not be verified safely.',
            ];
        }

        $batch = ApprovalBatch::query()->where('workspace_id', $plan->workspace_id)
            ->where('execution_plan_id', $plan->id)
            ->with(['decisionBy', 'grants.events', 'events'])
            ->first();
        $state = $batch?->state->value ?? ApprovalBatchState::Pending->value;
        if ($batch?->state === ApprovalBatchState::Approved
            && ($batch->expires_at === null || ! now()->lessThan($batch->expires_at))) {
            $state = ApprovalBatchState::Expired->value;
        } elseif ($batch?->state === ApprovalBatchState::Approved
            && $this->grantMismatch($batch, $plan, $actions->count(), $inputHashes)) {
            $state = ApprovalBatchState::Invalidated->value;
        }

        $availableActions = [];
        if ($owner && $batch === null && $plan->status === ExecutionPlanStatus::AwaitingApproval) {
            if ($blockers === []) {
                $availableActions[] = 'approve';
            }
            $availableActions[] = 'reject';
        }
        if ($owner && $state === ApprovalBatchState::Approved->value) {
            $availableActions[] = 'revoke';
        }

        return [
            'state' => $state,
            'batch_id' => $batch?->id,
            'plan_hash' => (string) $plan->plan_hash,
            'display_manifest_hash' => $this->manifest->hash($plan, $actions),
            'action_fingerprints' => $this->manifest->actionFingerprints($plan, $actions),
            'action_count' => $actions->count(),
            'policy_version_id' => (string) $plan->outreach_policy_version_id,
            'expires_at' => $batch?->expires_at?->toAtomString(),
            'decided_at' => $batch?->decided_at?->toAtomString(),
            'decision_by' => $batch?->decisionBy === null ? null : [
                'id' => (int) $batch->decisionBy->id,
                'name' => (string) $batch->decisionBy->name,
            ],
            'reason_code' => $batch?->reason_code,
            'reason_detail' => $batch?->reason_detail,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'available_actions' => $availableActions,
            'consequence' => 'Approval grants authority only for these exact action fingerprints until expiry; it does not launch or send email.',
            'grants' => $batch?->grants->map(fn (ApprovalGrant $grant): array => [
                'id' => (string) $grant->id,
                'action_id' => (string) $grant->execution_plan_action_id,
                'action_fingerprint' => (string) $grant->action_fingerprint,
                'decision' => $grant->decision->value,
                'expires_at' => $grant->expires_at?->toAtomString(),
                'latest_event' => $grant->events->sortByDesc('occurred_at')->first()?->event->value,
            ])->values()->all() ?? [],
        ];
    }

    /** @param array<string,string> $inputHashes */
    private function grantMismatch(
        ApprovalBatch $batch,
        ExecutionPlan $plan,
        int $actionCount,
        array $inputHashes,
    ): bool {
        if ((int) $batch->action_count !== $actionCount
            || ! hash_equals((string) $batch->plan_hash, (string) $plan->plan_hash)
            || $batch->grants->count() !== $actionCount) {
            return true;
        }
        foreach ($batch->grants as $grant) {
            if ($grant->decision !== ApprovalGrantDecision::Approved
                || ! hash_equals(
                    (string) $grant->policy_input_hash,
                    (string) ($inputHashes[(string) $grant->execution_plan_action_id] ?? ''),
                )) {
                return true;
            }
        }

        return false;
    }
}
