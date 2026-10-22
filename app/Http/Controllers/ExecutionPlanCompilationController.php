<?php

namespace App\Http\Controllers;

use App\Domain\Objectives\Exceptions\ExecutionPlanSnapshotConflict;
use App\Domain\Objectives\Services\CompileExecutionPlan;
use App\Http\Requests\MutateExecutionPlanRequest;
use App\Models\ExecutionPlan;
use Illuminate\Http\RedirectResponse;

final class ExecutionPlanCompilationController extends Controller
{
    public function __invoke(
        MutateExecutionPlanRequest $request,
        string $plan,
        CompileExecutionPlan $compile,
    ): RedirectResponse {
        $model = ExecutionPlan::query()
            ->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey($plan)
            ->firstOrFail();
        try {
            $request->assertCurrentSnapshot($model, $plan);
        } catch (ExecutionPlanSnapshotConflict $conflict) {
            return back()->withErrors(['plan' => $conflict->getMessage()]);
        }

        $compilation = $compile->handle($model, $request->user());

        $campaignCount = $compilation->campaigns()->count();

        return back()->with(
            'success',
            'The approved plan was prepared as '.$campaignCount.' campaign'.
                ($campaignCount === 1 ? '.' : 's.').' Nothing was sent.',
        );
    }
}
