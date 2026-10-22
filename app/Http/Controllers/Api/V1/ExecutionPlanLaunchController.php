<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Campaigns\Exceptions\GovernedExecutionBlocked;
use App\Domain\Objectives\Exceptions\ExecutionPlanSnapshotConflict;
use App\Domain\Objectives\Services\LaunchObjectiveExecution;
use App\Http\Controllers\Controller;
use App\Http\Requests\MutateExecutionPlanRequest;
use App\Models\ExecutionPlan;
use Illuminate\Http\JsonResponse;

final class ExecutionPlanLaunchController extends Controller
{
    public function __invoke(
        MutateExecutionPlanRequest $request,
        string $plan,
        LaunchObjectiveExecution $launch,
    ): JsonResponse {
        abort_if(trim((string) $request->header('Idempotency-Key', '')) === '', 422, 'Idempotency-Key header is required.');
        $model = ExecutionPlan::query()
            ->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey($plan)
            ->firstOrFail();
        try {
            $request->assertCurrentSnapshot($model, $plan);
        } catch (ExecutionPlanSnapshotConflict $conflict) {
            return response()->json(['error' => [
                'type' => 'conflict',
                'code' => 'execution_plan_snapshot_changed',
                'message' => $conflict->getMessage(),
            ]], 409);
        }

        try {
            $launched = $launch->handle($model, $request->user());
        } catch (GovernedExecutionBlocked $blocked) {
            return response()->json(['error' => [
                'type' => 'conflict',
                'code' => $blocked->reasonCode,
                'message' => $blocked->getMessage(),
                'blockers' => $blocked->blockers,
            ]], 409);
        }

        return response()->json(['data' => [
            'id' => (string) $launched->id,
            'status' => $launched->status->value,
            'objective_run_id' => (string) $launched->objective_run_id,
            'campaign_ids' => $launched->compiledCampaigns->pluck('campaign_id')->map(intval(...))->sort()->values()->all(),
        ]]);
    }
}
