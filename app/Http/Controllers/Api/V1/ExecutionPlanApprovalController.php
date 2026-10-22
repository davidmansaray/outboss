<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Objectives\Exceptions\ApprovalConflict;
use App\Domain\Objectives\Services\ApproveExecutionPlan;
use App\Domain\Objectives\Services\RejectExecutionPlan;
use App\Domain\Objectives\Services\RevokeExecutionApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveExecutionPlanRequest;
use App\Http\Requests\RejectExecutionPlanRequest;
use App\Http\Requests\RevokeExecutionApprovalRequest;
use App\Http\Resources\ApprovalBatchResource;
use App\Models\ApprovalBatch;
use App\Models\ExecutionPlan;
use Illuminate\Http\JsonResponse;

class ExecutionPlanApprovalController extends Controller
{
    public function approve(
        ApproveExecutionPlanRequest $request,
        string $plan,
        ApproveExecutionPlan $approve,
    ): JsonResponse {
        $this->requireIdempotencyKey($request);
        try {
            $batch = $approve->handle(
                $this->plan($request, $plan),
                $request->user(),
                (string) $request->validated('plan_hash'),
                (string) $request->validated('display_manifest_hash'),
                $request->validated('action_fingerprints'),
                $request->validated('reason'),
            );

            return (new ApprovalBatchResource($batch))->response()->setStatusCode(201);
        } catch (ApprovalConflict $conflict) {
            return $this->conflict($conflict);
        }
    }

    public function reject(
        RejectExecutionPlanRequest $request,
        string $plan,
        RejectExecutionPlan $reject,
    ): JsonResponse {
        $this->requireIdempotencyKey($request);
        try {
            $batch = $reject->handle(
                $this->plan($request, $plan),
                $request->user(),
                (string) $request->validated('plan_hash'),
                (string) $request->validated('display_manifest_hash'),
                $request->validated('action_fingerprints'),
                (string) $request->validated('reason'),
            );

            return (new ApprovalBatchResource($batch))->response()->setStatusCode(201);
        } catch (ApprovalConflict $conflict) {
            return $this->conflict($conflict);
        }
    }

    public function revoke(
        RevokeExecutionApprovalRequest $request,
        string $batch,
        RevokeExecutionApproval $revoke,
    ): JsonResponse {
        $this->requireIdempotencyKey($request);
        $model = ApprovalBatch::query()
            ->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey($batch)->firstOrFail();
        try {
            return (new ApprovalBatchResource($revoke->handle(
                $model,
                $request->user(),
                (string) $request->validated('plan_hash'),
                (string) $request->validated('display_manifest_hash'),
                $request->validated('action_fingerprints'),
                (string) $request->validated('reason'),
            )))->response();
        } catch (ApprovalConflict $conflict) {
            return $this->conflict($conflict);
        }
    }

    private function plan(ApproveExecutionPlanRequest $request, string $id): ExecutionPlan
    {
        return ExecutionPlan::query()
            ->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey($id)->firstOrFail();
    }

    private function requireIdempotencyKey(ApproveExecutionPlanRequest $request): void
    {
        abort_if(trim((string) $request->header('Idempotency-Key', '')) === '', 422, 'Idempotency-Key header is required.');
    }

    private function conflict(ApprovalConflict $conflict): JsonResponse
    {
        return response()->json(['error' => [
            'type' => 'conflict',
            'code' => $conflict->reasonCode,
            'message' => $conflict->getMessage(),
            'blockers' => $conflict->blockers,
        ]], 409);
    }
}
