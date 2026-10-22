<?php

namespace App\Http\Controllers;

use App\Domain\Objectives\Exceptions\ObjectiveRunConflict;
use App\Domain\Objectives\Services\StartObjectiveRun;
use App\Http\Requests\StartObjectiveRunRequest;
use App\Models\GtmExperiment;
use App\Models\Objective;
use Illuminate\Http\RedirectResponse;

final class ObjectiveRunController extends Controller
{
    public function store(
        StartObjectiveRunRequest $request,
        string $objective,
        StartObjectiveRun $start,
    ): RedirectResponse {
        $workspaceId = (int) $request->user()->currentWorkspace()->id;
        $objectiveModel = Objective::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($objective)
            ->firstOrFail();
        $experiment = GtmExperiment::query()
            ->where('workspace_id', $workspaceId)
            ->where('objective_id', $objectiveModel->id)
            ->where('objective_version_id', $objectiveModel->current_version_id)
            ->whereKey((string) $request->validated('gtm_experiment_id'))
            ->firstOrFail();

        try {
            $run = $start->handle(
                $objectiveModel,
                $experiment,
                $request->user(),
                (string) $request->validated('idempotency_key'),
            );
        } catch (ObjectiveRunConflict $conflict) {
            return back()->withErrors(['run' => $conflict->getMessage()]);
        }

        return redirect()
            ->route('objectives.index', ['focus' => $objectiveModel->id])
            ->with('success', 'Plan generation started as run '.$run->run_number.'.');
    }
}
