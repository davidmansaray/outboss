<?php

namespace App\Http\Controllers;

use App\Domain\Campaigns\Exceptions\GovernedExecutionBlocked;
use App\Domain\Campaigns\Services\PrepareHumanCampaignReview;
use App\Domain\Campaigns\Services\RetireExpiredHumanReview;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use App\Domain\Objectives\Exceptions\ApprovalConflict;
use App\Domain\Objectives\Services\ApproveExecutionPlan;
use App\Domain\Objectives\Services\BuildHumanExecutionPlan;
use App\Domain\Objectives\Services\CancelObjectiveRun;
use App\Domain\Objectives\Services\CompileExecutionPlan;
use App\Domain\Objectives\Services\ExactExecutionPlanValidator;
use App\Domain\Objectives\Services\LaunchObjectiveExecution;
use App\Domain\Objectives\Support\ApprovalManifest;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\ExecutionPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class HumanCampaignReviewController extends Controller
{
    public function show(Request $request, Campaign $campaign): Response
    {
        $this->authorizeOwner($request, $campaign);
        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        $composition = CampaignComposition::query()->where('workspace_id', $workspaceId)
            ->where('campaign_id', $campaign->id)->orderByDesc('revision')->firstOrFail();
        $plan = ExecutionPlan::query()->where('workspace_id', $workspaceId)
            ->where('origin', ExecutionOrigin::Human)
            ->whereNotIn('status', [ExecutionPlanStatus::Cancelled, ExecutionPlanStatus::Superseded])
            ->whereHas('objectiveRun', fn ($query) => $query->where('workspace_id', $workspaceId)
                ->whereIn('campaign_composition_id', CampaignComposition::query()->where('workspace_id', $workspaceId)->where('campaign_id', $campaign->id)->select('id')))
            ->latest('created_at')->first();
        $batch = $plan?->approvalBatches()->where('workspace_id', $workspaceId)->first();
        $approvalStatus = $batch?->state->value ?? 'pending';
        if ($approvalStatus === 'approved' && ($batch->expires_at === null || ! $batch->expires_at->isFuture())) {
            $approvalStatus = 'expired';
        }
        $resuming = in_array($campaign->getRawOriginal('status'), ['paused', 'completed'], true)
            && $campaign->recipients()->whereIn('status', ['queued', 'active', 'paused'])->exists();
        $validation = $plan !== null && in_array($plan->status, [ExecutionPlanStatus::AwaitingApproval, ExecutionPlanStatus::Approved, ExecutionPlanStatus::Compiled, ExecutionPlanStatus::Executing], true)
            ? app(ExactExecutionPlanValidator::class)->handle($plan) : null;
        $context = $plan?->objectiveVersion;
        $manifest = app(ApprovalManifest::class);

        return Inertia::render('campaigns/Review', [
            'campaign' => ['id' => $campaign->id, 'name' => $campaign->name, 'status' => $campaign->getRawOriginal('status')],
            'revision' => (int) $composition->revision,
            'operation_id' => (string) Str::uuid(),
            'review' => $plan === null ? null : [
                'id' => $plan->id, 'plan_hash' => $plan->plan_hash,
                'prepared_at' => $plan->created_at->toAtomString(),
                'status' => $plan->status->value, 'approval_status' => $approvalStatus,
                'approval_expires_at' => $batch?->expires_at?->toAtomString(),
                'approval_lifetime' => $plan->schema_version === BuildHumanExecutionPlan::VERSION ? 'unchanged_campaign' : 'time_limited',
                'campaign_window' => $manifest->build($plan)['campaign_window'] ?? null,
                'display_manifest_hash' => $manifest->hash($plan),
                'action_fingerprints' => $manifest->actionFingerprints($plan),
                'blockers' => $validation->blockers ?? [],
                'can_approve' => $batch === null && $plan->status === ExecutionPlanStatus::AwaitingApproval && $validation?->allowed(),
                'can_discard' => $batch === null && $plan->status === ExecutionPlanStatus::AwaitingApproval,
                'can_return_to_draft' => in_array($approvalStatus, ['expired', 'invalidated', 'rejected', 'revoked'], true)
                    && in_array($plan->status, [ExecutionPlanStatus::Approved, ExecutionPlanStatus::Rejected, ExecutionPlanStatus::Revoked], true)
                    && ! $plan->compilations()->exists(),
                'can_launch' => $approvalStatus === 'approved' && (in_array($plan->status, [ExecutionPlanStatus::Approved, ExecutionPlanStatus::Compiled], true)
                    || ($resuming && $plan->status === ExecutionPlanStatus::Executing)) && $validation?->allowed(),
                'objective' => $context?->name,
                'offer' => $context?->offerVersion?->payload['name'] ?? null,
                'offer_id' => $context?->offerVersion?->offer_id,
                'messages' => $plan->actions()->where('workspace_id', $workspaceId)->orderBy('id')->get()
                    ->map(fn ($action): array => [
                        'id' => $action->id, 'to' => $action->destination_email_address,
                        'status' => $action->state->value,
                        'from' => $action->sender_address_snapshot, 'sender_name' => $action->sender_display_name_snapshot,
                        'reply_to' => $action->reply_to_snapshot, 'subject' => $action->subject,
                        'body' => $action->body_text, 'starts_at' => $action->scheduled_not_before->toAtomString(),
                        'ends_at' => $action->scheduled_not_after->toAtomString(),
                    ])->all(),
            ],
        ]);
    }

    public function prepare(Request $request, Campaign $campaign, PrepareHumanCampaignReview $prepare): RedirectResponse
    {
        $this->authorizeOwner($request, $campaign);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'operation_id' => ['required', 'uuid'],
            'replace_plan_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        DB::transaction(function () use ($request, $campaign, $prepare, $data): void {
            Campaign::query()->where('workspace_id', $campaign->getAttribute('workspace_id'))->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $old = null;
            if (! empty($data['replace_plan_id'])) {
                $old = ExecutionPlan::query()->where('workspace_id', $campaign->getAttribute('workspace_id'))->whereKey($data['replace_plan_id'])->lockForUpdate()->firstOrFail();
                $this->authorizeReview($request, $campaign, $old, false);
                if (! in_array($old->status, [ExecutionPlanStatus::AwaitingApproval, ExecutionPlanStatus::Cancelled], true) || $old->approvalBatches()->exists() || $old->compilations()->exists()) {
                    throw ValidationException::withMessages(['review' => 'An approved review cannot be replaced by setup changes.']);
                }
                app(CancelObjectiveRun::class)->handle($old->objectiveRun, $request->user(), 'Owner requested exact review of updated campaign settings.');
            }
            // Cancellation rolls back too if preparation fails.
            $prepare->handle($campaign, $request->user(), (int) $data['expected_revision'], $data['operation_id']);
        }, 3);

        return redirect()->route('campaigns.review', $campaign);
    }

    private function authorizeOwner(Request $request, Campaign $campaign): void
    {
        $workspace = $request->user()->currentWorkspace();
        abort_unless((int) $campaign->getAttribute('workspace_id') === (int) $workspace->id, 404);
        $this->authorize('view', $campaign);
        abort_unless($workspace->isOwner($request->user()), 403);
    }

    public function approve(Request $request, Campaign $campaign, ExecutionPlan $plan): RedirectResponse
    {
        $this->authorizeReview($request, $campaign, $plan);
        $data = $this->decisionInput($request);
        $request->validate(['confirmed' => ['accepted']]);
        try {
            app(ApproveExecutionPlan::class)->handle($plan, $request->user(), $data['plan_hash'], $data['display_manifest_hash'], $data['action_fingerprints']);
        } catch (ApprovalConflict $error) {
            throw ValidationException::withMessages(['review' => implode(' ', array_column($error->blockers, 'message')) ?: $error->getMessage()]);
        }

        return redirect()->route('campaigns.review', $campaign);
    }

    public function launch(Request $request, Campaign $campaign, ExecutionPlan $plan): RedirectResponse
    {
        $this->authorizeReview($request, $campaign, $plan);
        $data = $this->decisionInput($request);
        $manifest = app(ApprovalManifest::class);
        $fingerprints = $data['action_fingerprints'];
        sort($fingerprints, SORT_STRING);
        if (! hash_equals($plan->plan_hash, $data['plan_hash'])
            || ! hash_equals($manifest->hash($plan), $data['display_manifest_hash'])
            || $manifest->actionFingerprints($plan) !== $fingerprints) {
            throw ValidationException::withMessages(['review' => 'This review changed. Reload and check the exact messages before launching.']);
        }
        try {
            app(CompileExecutionPlan::class)->handle($plan, $request->user());
            app(LaunchObjectiveExecution::class)->handle($plan->fresh(), $request->user());
        } catch (GovernedExecutionBlocked $error) {
            throw ValidationException::withMessages(['review' => implode(' ', array_column($error->blockers, 'message')) ?: $error->getMessage()]);
        }

        return redirect()->route('campaigns.review', $campaign);
    }

    public function discard(Request $request, Campaign $campaign, ExecutionPlan $plan): RedirectResponse
    {
        $this->authorizeReview($request, $campaign, $plan, false);
        DB::transaction(function () use ($request, $plan): void {
            $locked = ExecutionPlan::query()->where('workspace_id', $plan->workspace_id)->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ExecutionPlanStatus::AwaitingApproval || $locked->approvalBatches()->exists() || $locked->compilations()->exists()) {
                throw ValidationException::withMessages(['review' => 'Only an unapproved review can be discarded. Approved delivery history is retained.']);
            }
            app(CancelObjectiveRun::class)->handle($locked->objectiveRun, $request->user(), 'Owner discarded the unapproved human campaign review.');
        }, 3);

        return redirect()->route('campaigns.show', ['campaign' => $campaign, 'step' => 'sequence']);
    }

    public function returnToDraft(Request $request, Campaign $campaign, ExecutionPlan $plan, RetireExpiredHumanReview $retire): RedirectResponse
    {
        $this->authorizeReview($request, $campaign, $plan, false);
        $data = $this->decisionInput($request);
        $retire->handle($campaign, $plan, $request->user(), $data['plan_hash'], $data['display_manifest_hash'], $data['action_fingerprints']);

        return redirect()->route('campaigns.show', $campaign);
    }

    private function authorizeReview(Request $request, Campaign $campaign, ExecutionPlan $plan, bool $requireCurrent = true): void
    {
        $this->authorizeOwner($request, $campaign);
        abort_unless((int) $plan->workspace_id === (int) $campaign->getAttribute('workspace_id') && $plan->getAttribute('origin') === ExecutionOrigin::Human, 404);
        $composition = CampaignComposition::query()->where('workspace_id', $plan->workspace_id)->where('campaign_id', $campaign->id)->latest('revision')->firstOrFail();
        $run = $plan->objectiveRun()->where('workspace_id', $plan->workspace_id)->firstOrFail();
        abort_unless(CampaignComposition::query()->where('workspace_id', $plan->workspace_id)->where('campaign_id', $campaign->id)->whereKey($run->getAttribute('campaign_composition_id'))->exists(), 404);
        if ($requireCurrent && $run->getAttribute('campaign_composition_id') !== $composition->id) {
            throw ValidationException::withMessages(['review' => 'Campaign selections changed. Discard this unapproved review and prepare the saved campaign again.']);
        }
        $request->validate(['expected_revision' => ['required', 'integer', 'in:'.$composition->revision]]);
    }

    /** @return array{plan_hash:string, display_manifest_hash:string, action_fingerprints:list<string>} */
    private function decisionInput(Request $request): array
    {
        return $request->validate([
            'plan_hash' => ['required', 'string', 'size:64'],
            'display_manifest_hash' => ['required', 'string', 'size:64'],
            'action_fingerprints' => ['required', 'array', 'min:1'],
            'action_fingerprints.*' => ['required', 'string', 'size:64', 'distinct'],
        ]);
    }
}
