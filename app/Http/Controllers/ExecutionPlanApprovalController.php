<?php

namespace App\Http\Controllers;

use App\Domain\Objectives\Exceptions\ApprovalConflict;
use App\Domain\Objectives\Services\ApproveExecutionPlan;
use App\Domain\Objectives\Services\RejectExecutionPlan;
use App\Http\Requests\ApproveExecutionPlanRequest;
use App\Http\Requests\RejectExecutionPlanRequest;
use App\Models\ExecutionPlan;
use Illuminate\Http\RedirectResponse;

class ExecutionPlanApprovalController extends Controller
{
    public function approve(
        ApproveExecutionPlanRequest $request,
        string $plan,
        ApproveExecutionPlan $approve,
    ): RedirectResponse {
        try {
            $batch = $approve->handle(
                $this->plan($request, $plan),
                $request->user(),
                (string) $request->validated('plan_hash'),
                (string) $request->validated('display_manifest_hash'),
                $request->validated('action_fingerprints'),
                $request->validated('reason'),
            );

            return back()->with('success', 'Exact plan approved until '.$batch->expires_at?->toAtomString().'.');
        } catch (ApprovalConflict $conflict) {
            return back()->withErrors(['approval' => $this->message($conflict)]);
        }
    }

    public function reject(
        RejectExecutionPlanRequest $request,
        string $plan,
        RejectExecutionPlan $reject,
    ): RedirectResponse {
        try {
            $reject->handle(
                $this->plan($request, $plan),
                $request->user(),
                (string) $request->validated('plan_hash'),
                (string) $request->validated('display_manifest_hash'),
                $request->validated('action_fingerprints'),
                (string) $request->validated('reason'),
            );

            return back()->with('success', 'Exact plan rejected.');
        } catch (ApprovalConflict $conflict) {
            return back()->withErrors(['approval' => $this->message($conflict)]);
        }
    }

    private function message(ApprovalConflict $conflict): string
    {
        return (string) ($conflict->blockers[0]['message'] ?? $conflict->getMessage());
    }

    private function plan(ApproveExecutionPlanRequest $request, string $id): ExecutionPlan
    {
        return ExecutionPlan::query()
            ->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey($id)
            ->firstOrFail();
    }
}
