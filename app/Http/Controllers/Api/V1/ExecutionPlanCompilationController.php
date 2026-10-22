<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Objectives\Exceptions\ExecutionPlanSnapshotConflict;
use App\Domain\Objectives\Services\CompileExecutionPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\MutateExecutionPlanRequest;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanCompilation;
use BackedEnum;
use Illuminate\Http\JsonResponse;

final class ExecutionPlanCompilationController extends Controller
{
    public function __invoke(
        MutateExecutionPlanRequest $request,
        string $plan,
        CompileExecutionPlan $compile,
    ): JsonResponse {
        abort_if(
            trim((string) $request->header('Idempotency-Key', '')) === '',
            422,
            'Idempotency-Key header is required.',
        );

        $model = ExecutionPlan::query()
            ->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey($plan)
            ->firstOrFail();
        try {
            $request->assertCurrentSnapshot($model, $plan);
        } catch (ExecutionPlanSnapshotConflict $conflict) {
            return $this->snapshotConflict($conflict);
        }

        $compilation = $compile->handle($model, $request->user())
            ->loadMissing(['campaigns.campaign']);

        return response()->json(['data' => [
            'id' => (string) $compilation->id,
            'execution_plan_id' => (string) $compilation->execution_plan_id,
            'attempt' => (int) $compilation->attempt,
            'state' => $compilation->state->value,
            'input_hash' => (string) $compilation->input_hash,
            'output_hash' => $compilation->output_hash,
            'started_at' => $compilation->started_at->toAtomString(),
            'finished_at' => $compilation->finished_at?->toAtomString(),
            'campaigns' => $this->campaigns($compilation),
        ]], 201);
    }

    /** @return list<array{id:int,arm_id:string,name:string,status:string}> */
    private function campaigns(ExecutionPlanCompilation $compilation): array
    {
        $campaigns = [];
        foreach ($compilation->campaigns as $mapping) {
            $status = $mapping->campaign->getAttribute('status');
            $campaigns[] = [
                'id' => (int) $mapping->campaign_id,
                'arm_id' => (string) $mapping->gtm_experiment_arm_id,
                'name' => (string) $mapping->campaign->name,
                'status' => $status instanceof BackedEnum ? (string) $status->value : (string) $status,
            ];
        }
        usort($campaigns, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $campaigns;
    }

    private function snapshotConflict(ExecutionPlanSnapshotConflict $conflict): JsonResponse
    {
        return response()->json(['error' => [
            'type' => 'conflict',
            'code' => 'execution_plan_snapshot_changed',
            'message' => $conflict->getMessage(),
        ]], 409);
    }
}
