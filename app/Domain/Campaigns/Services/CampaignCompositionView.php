<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\ExecutionAuthorityRevocation;
use App\Models\ExecutionAuthorityScope;
use App\Models\ObjectiveGoalVersion;
use App\Models\OfferVersion;
use App\Models\User;
use Illuminate\Support\Carbon;

final class CampaignCompositionView
{
    /** @return array<string, mixed> */
    public function handle(Campaign $campaign, User $actor): array
    {
        $workspaceId = $campaign->getAttribute('workspace_id');
        $current = CampaignComposition::query()->where('workspace_id', $workspaceId)
            ->where('campaign_id', $campaign->id)->orderByDesc('revision')->first();
        $goals = ObjectiveGoalVersion::query()->where('objective_goal_versions.workspace_id', $workspaceId)
            ->join('objectives', 'objectives.id', '=', 'objective_goal_versions.objective_id')
            ->where(fn ($query) => $query->whereColumn('objectives.current_goal_version_id', 'objective_goal_versions.id')
                ->orWhere('objective_goal_versions.id', $current->objective_goal_version_id ?? null))
            ->select('objective_goal_versions.*', 'objectives.current_goal_version_id', 'objectives.status as objective_status')->orderBy('name')->get();
        $offers = OfferVersion::query()->where('offer_versions.workspace_id', $workspaceId)
            ->join('offers', 'offers.id', '=', 'offer_versions.offer_id')
            ->where(fn ($query) => $query->whereColumn('offers.current_version_id', 'offer_versions.id')
                ->orWhere('offer_versions.id', $current->offer_version_id ?? null))
            ->select('offer_versions.*', 'offers.status as offer_status', 'offers.current_version_id')->get();

        $revokedScopeIds = ExecutionAuthorityRevocation::query()->where('workspace_id', $workspaceId)
            ->pluck('execution_authority_scope_id')->all();

        return [
            'allowance_proposal' => [
                'name' => mb_substr($campaign->name.' allowance', 0, 120),
                'maximum_people' => max(1, $campaign->recipients()->humanReviewAudience()->count()),
                'maximum_accounts' => max(1, $campaign->recipients()->humanReviewAudience()->distinct()->count('account_id')),
                'maximum_messages' => max(1, $campaign->recipients()->humanReviewAudience()->count() * (($campaign->draftVersion ?? $campaign->publishedVersion)?->activeSteps->count() ?? 1)),
            ],
            'revision' => $current->revision ?? 0,
            'objective_goal_version_id' => $current->objective_goal_version_id ?? null,
            'offer_version_id' => $current->offer_version_id ?? null,
            'execution_authority_scope_id' => $current?->execution_authority_scope_id,
            'target_contribution_count' => $current?->target_contribution_count,
            'sending_starts_at' => $current?->sending_starts_at?->toAtomString(),
            'sending_ends_at' => $current?->sending_ends_at?->toAtomString(),
            'sending_forecast' => app(CampaignSendingForecast::class)->handle($campaign, $current),
            'execution_scopes' => ExecutionAuthorityScope::query()->where('workspace_id', $workspaceId)
                ->orderBy('name')->get()->map(fn ($scope) => [
                    'id' => $scope->id, 'name' => $scope->name, 'authority' => $scope->authority,
                    'expired' => Carbon::parse($scope->authority['ends_at'])->isPast(),
                    'revoked' => in_array($scope->id, $revokedScopeIds, true),
                ])->values()->all(),
            'editable' => $campaign->getAttribute('status') === CampaignStatus::Draft
                && $campaign->launched_at === null && $campaign->mail_experiment_cohort_id === null
                && ! $campaign->executionPlanMappings()->exists()
                && $actor->currentWorkspace()->isOwner($actor),
            'goals' => $goals->map(fn ($goal) => [
                'id' => $goal->id, 'objective_id' => $goal->objective_id, 'name' => $goal->name,
                'version' => $goal->version, 'target_count' => $goal->target_count,
                'status' => $goal->getAttribute('objective_status'),
                'measurement_configured' => $goal->conversion_definition_version_id !== null,
                'measurement' => $goal->measurement, 'deadline' => $goal->deadline?->toDateString(),
                'current' => $goal->id === $goal->getAttribute('current_goal_version_id'),
            ])->values()->all(),
            'offers' => $offers->map(fn ($offer) => [
                'id' => $offer->id, 'offer_id' => $offer->offer_id, 'name' => $offer->payload['name'] ?? 'Untitled Offer',
                'version' => $offer->version, 'status' => $offer->getAttribute('offer_status'),
                'current' => $offer->id === $offer->getAttribute('current_version_id'),
            ])->sortBy('name')->values()->all(),
        ];
    }
}
