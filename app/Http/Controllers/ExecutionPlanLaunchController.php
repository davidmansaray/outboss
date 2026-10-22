<?php

namespace App\Http\Controllers;

use App\Domain\Campaigns\Exceptions\GovernedExecutionBlocked;
use App\Domain\Objectives\Exceptions\ExecutionPlanSnapshotConflict;
use App\Domain\Objectives\Services\LaunchObjectiveExecution;
use App\Http\Requests\MutateExecutionPlanRequest;
use App\Models\ExecutionPlan;
use Illuminate\Http\RedirectResponse;

final class ExecutionPlanLaunchController extends Controller
{
    public function __invoke(
        MutateExecutionPlanRequest $request,
        string $plan,
        LaunchObjectiveExecution $launch,
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

        try {
            $launch->handle($model, $request->user());
        } catch (GovernedExecutionBlocked $blocked) {
            return back()->withErrors([
                'launch' => (string) ($blocked->blockers[0]['message'] ?? 'The plan cannot launch safely yet.'),
            ]);
        }

        return back()->with('success', 'Objective execution launched for every experiment arm.');
    }
}
