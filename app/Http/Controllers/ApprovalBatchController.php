<?php

namespace App\Http\Controllers;

use App\Domain\Objectives\Exceptions\ApprovalConflict;
use App\Domain\Objectives\Services\RevokeExecutionApproval;
use App\Http\Requests\RevokeExecutionApprovalRequest;
use App\Models\ApprovalBatch;
use Illuminate\Http\RedirectResponse;

class ApprovalBatchController extends Controller
{
    public function revoke(
        RevokeExecutionApprovalRequest $request,
        string $batch,
        RevokeExecutionApproval $revoke,
    ): RedirectResponse {
        $model = ApprovalBatch::query()
            ->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey($batch)
            ->firstOrFail();
        try {
            $revoke->handle(
                $model,
                $request->user(),
                (string) $request->validated('plan_hash'),
                (string) $request->validated('display_manifest_hash'),
                $request->validated('action_fingerprints'),
                (string) $request->validated('reason'),
            );

            return back()->with('success', 'Exact execution approval revoked.');
        } catch (ApprovalConflict $conflict) {
            return back()->withErrors([
                'approval' => (string) ($conflict->blockers[0]['message'] ?? $conflict->getMessage()),
            ]);
        }
    }
}
