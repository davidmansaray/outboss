<?php

namespace App\Http\Controllers;

use App\Domain\Campaigns\Services\SaveCampaignComposition;
use App\Domain\Gtm\Exceptions\GtmPayloadValidationException;
use App\Domain\Gtm\Services\CreateOffer;
use App\Domain\Objectives\Services\CreateExecutionAuthorityScope;
use App\Domain\Objectives\Services\SaveObjectiveGoal;
use App\Models\Campaign;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CampaignCompositionController extends Controller
{
    public function save(Request $request, Campaign $campaign, SaveCampaignComposition $save): RedirectResponse
    {
        $this->authorize('update', $campaign);
        $workspace = $request->user()->currentWorkspace();
        abort_unless((int) $campaign->getAttribute('workspace_id') === (int) $workspace->id, 404);
        abort_unless($workspace->isOwner($request->user()), 403);
        $data = $request->validate([
            'action' => ['required', 'in:select,create_goal,create_offer,create_scope'],
            'objective_goal_version_id' => ['present', 'nullable', 'uuid'],
            'offer_version_id' => ['present', 'nullable', 'uuid'],
            'execution_authority_scope_id' => ['sometimes', 'nullable', 'uuid'],
            'target_contribution_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'sending_starts_at' => ['sometimes', 'nullable', 'date'],
            'sending_ends_at' => ['sometimes', 'nullable', 'date'],
            'authority' => ['required_if:action,create_scope', 'array'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'operation_id' => ['required', 'uuid'],
            'name' => ['required_unless:action,select', 'nullable', 'string', 'max:120'],
            'target_count' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'promise' => ['nullable', 'string', 'max:5000'],
        ]);
        try {
            DB::transaction(function () use ($request, $workspace, $campaign, $save, $data): void {
                // Match independent goal creation's workspace-first lock order.
                Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
                if ($data['action'] === 'create_goal') {
                    $goal = app(SaveObjectiveGoal::class)->handle($workspace, $request->user(), [
                        'name' => $data['name'], 'measurement' => 'meeting_booked',
                        'target_count' => $data['target_count'] ?? null, 'creation_key' => $data['operation_id'],
                    ]);
                    $data['objective_goal_version_id'] = $goal->current_goal_version_id;
                } elseif ($data['action'] === 'create_offer') {
                    $offer = app(CreateOffer::class)->handle($workspace, $request->user(), [
                        'name' => $data['name'], 'promise' => $data['promise'] ?? '',
                        'target_audience' => '', 'desired_outcome' => '', 'call_to_action' => '', 'notes' => '',
                    ], $data['operation_id']);
                    $data['offer_version_id'] = $offer->current_version_id;
                } elseif ($data['action'] === 'create_scope') {
                    $scope = app(CreateExecutionAuthorityScope::class)->handle($workspace, $request->user(), [
                        'name' => $data['name'], 'authority' => $data['authority'],
                        'operation_id' => $data['operation_id'],
                    ]);
                    $data['execution_authority_scope_id'] = $scope->id;
                }
                $save->handle($campaign, $request->user(), $data);
            }, 3);
        } catch (GtmPayloadValidationException $error) {
            throw ValidationException::withMessages($error->errors);
        }

        return redirect()->route('campaigns.show', $campaign);
    }
}
