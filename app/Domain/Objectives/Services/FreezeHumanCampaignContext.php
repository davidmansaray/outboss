<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use App\Domain\Objectives\Enums\ObjectiveStatus;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\AudienceSnapshot;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\ConversionDefinitionVersion;
use App\Models\ExecutionAuthorityLegacyMapping;
use App\Models\ExecutionAuthorityRevocation;
use App\Models\ExecutionAuthorityScope;
use App\Models\Objective;
use App\Models\ObjectiveGoalVersion;
use App\Models\ObjectiveVersion;
use App\Models\OfferVersion;
use App\Models\OutreachPolicy;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Internal compiler step. Caller freezes the audience and persists the run atomically. */
final class FreezeHumanCampaignContext
{
    public const SCHEMA = 'human_campaign_context_v1';

    public function handle(Campaign $campaign, CampaignComposition $composition, AudienceSnapshot $snapshot, User $actor, bool $existingOnly = false, ?Workspace $workspace = null): ObjectiveVersion
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Human context requires the enclosing compiler transaction.');
        }
        $workspace ??= $actor->currentWorkspace();
        abort_unless((int) $campaign->getAttribute('workspace_id') === (int) $workspace->id, 404);
        abort_unless($workspace->isOwner($actor), 403);
        $campaign = Campaign::query()->where('workspace_id', $workspace->id)->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
        if (! $existingOnly) {
            app(GovernedCampaignInputGuard::class)->assertCampaignMutable($campaign);
            app(FrozenMailExperimentInputGuard::class)->assertCampaignMutable($campaign);
        }
        $current = CampaignComposition::query()->where('workspace_id', $workspace->id)->where('campaign_id', $campaign->id)
            ->orderByDesc('revision')->lockForUpdate()->first();
        if ($current === null || $current->id !== $composition->id
            || (! $existingOnly && ($campaign->launched_at !== null || $campaign->getAttribute('status') !== CampaignStatus::Draft))) {
            $this->reject('Prepare the current unlaunched campaign composition.');
        }
        $goal = ObjectiveGoalVersion::query()->where('workspace_id', $workspace->id)->whereKey($current->objective_goal_version_id)->firstOrFail();
        $objective = Objective::query()->where('workspace_id', $workspace->id)->whereKey($goal->objective_id)->lockForUpdate()->firstOrFail();
        if (! in_array($objective->status, [ObjectiveStatus::Draft, ObjectiveStatus::Ready, ObjectiveStatus::Active], true)) {
            $this->reject('This Objective is paused, blocked or terminal and cannot prepare new execution.');
        }
        $snapshot = AudienceSnapshot::query()->where('workspace_id', $workspace->id)->whereKey($snapshot->id)->firstOrFail();
        if ($snapshot->objective_id !== $goal->objective_id || $snapshot->status !== 'frozen' || $snapshot->included_people_count < 1) {
            $this->reject('Freeze this campaign audience for the selected Objective.');
        }
        $selected = $campaign->recipients()->humanReviewAudience()->orderBy('person_id')->pluck('person_id')->map(strval(...))->all();
        $frozen = $snapshot->members()->where('result', 'included')->orderBy('person_id')->pluck('person_id')->map(strval(...))->all();
        if ($selected !== $frozen || count(array_unique($selected)) !== count($selected)) {
            $this->reject('The frozen audience must match every selected campaign prospect exactly.');
        }
        $scope = ExecutionAuthorityScope::query()->where('workspace_id', $workspace->id)->whereKey($current->execution_authority_scope_id)->lockForUpdate()->firstOrFail();
        $authority = app(ValidateExecutionAuthority::class)->handle($scope->authority);
        if (! hash_equals($scope->content_hash, CanonicalObjectivePayload::hash(['name' => $scope->name, 'authority' => $authority]))
            || ExecutionAuthorityRevocation::query()->where('workspace_id', $workspace->id)->where('execution_authority_scope_id', $scope->id)->lockForUpdate()->first() !== null) {
            $this->reject('The selected sending allowance is unavailable or revoked.');
        }
        if ($goal->execution_authority !== null && ! ExecutionAuthorityLegacyMapping::query()->where('workspace_id', $workspace->id)->where('objective_id', $goal->objective_id)->exists()) {
            $this->reject('Import the historical sending allowance explicitly before preparing execution.');
        }
        // Null dates mean start on launch and run until finished, not unlimited
        // authority. Preserve the separately approved allowance boundary. Use
        // immutable composition creation time so later validation is stable.
        $start = $current->sending_starts_at ?? Carbon::parse($current->created_at)->max(Carbon::parse($authority['starts_at']));
        $end = $current->sending_ends_at ?? Carbon::parse($authority['ends_at']);
        if (! $end->isFuture() || ! $end->greaterThan($start)
            || $start->lessThan(Carbon::parse($authority['starts_at'])) || $end->greaterThan(Carbon::parse($authority['ends_at']))) {
            $this->reject('The sending allowance must cover the campaign start and any optional deadline. Choose an available allowance before review.');
        }
        $definitionId = app(ValidateGoalMeasurement::class)->handle((int) $workspace->id, $goal->measurement, (string) $goal->conversion_definition_version_id);
        $definition = ConversionDefinitionVersion::query()->where('workspace_id', $workspace->id)->whereKey($definitionId)->firstOrFail();
        $offer = OfferVersion::query()->where('workspace_id', $workspace->id)->whereKey($current->offer_version_id)
            ->whereHas('offer', fn ($query) => $query->where('workspace_id', $workspace->id)->where('status', 'active')
                ->whereColumn('current_version_id', 'offer_versions.id'))->first();
        if ($offer === null) {
            $this->reject('Select the current active Offer version before preparing execution.');
        }
        $policy = OutreachPolicy::query()->where('workspace_id', $workspace->id)->where('status', 'active')->lockForUpdate()->first()?->currentVersion;
        if ($policy === null) {
            $this->reject('An active workspace outreach policy is required.');
        }
        $limits = [];
        foreach (['maximum_people', 'maximum_accounts', 'maximum_messages'] as $key) {
            $limits[$key] = min($authority[$key], (int) $policy->getAttribute($key), (int) config('objectives.safety.'.$key));
            if ($limits[$key] < 1) {
                $this->reject('The effective sending allowance is unavailable.');
            }
        }
        if ($snapshot->included_people_count > min($limits['maximum_people'], $limits['maximum_messages']) || $snapshot->included_accounts_count > $limits['maximum_accounts']) {
            $this->reject('The selected audience exceeds the effective sending limits.');
        }
        $days = $definition->attribution_window['days'] ?? 0;
        if ($days < 1 || $days > (int) config('objectives.safety.maximum_attribution_window_days')) {
            $this->reject('The measurement attribution window is unsupported.');
        }
        $dailySendLimit = $campaign->getAttribute('daily_send_limit');
        $payload = [
            'name' => $goal->name, 'description' => $goal->description,
            'conversion_definition_id' => $definition->conversion_definition_id, 'conversion_definition_version_id' => $definition->id,
            'segment_version_id' => $snapshot->segment_version_id, 'audience_snapshot_id' => $snapshot->id,
            'offer_id' => $offer->offer_id, 'offer_version_id' => $offer->id,
            'target_kind' => 'count', 'target_count' => $current->target_contribution_count, 'target_value' => null, 'currency' => null,
            'starts_at' => $start->toAtomString(), 'ends_at' => $end->toAtomString(), 'attribution_window_days' => $days,
            ...$limits, 'maximum_ai_cost_micros' => 0,
            'required_qualification_outcomes' => [],
            'minimum_qualification_confidence' => 0,
            'maximum_evidence_age_days' => $policy->getAttribute('evidence_constraints')['maximum_age_days'],
            'exclusions' => [], 'authority_envelope' => [
                'approval_required' => true, 'origin' => 'human', 'campaign_composition_id' => $current->id,
                'audience_policy' => 'human_selected_research_optional_v1',
                'objective_goal_version_id' => $goal->id, 'execution_authority_scope_id' => $scope->id,
                'execution_authority_content_hash' => $scope->content_hash, 'outreach_policy_version_id' => $policy->id,
                ...($dailySendLimit === null ? [] : ['daily_send_limit' => (int) $dailySendLimit]),
            ],
            'forecast_assumptions' => [], 'forecast_projection' => [], 'schema_version' => self::SCHEMA,
        ];
        $hash = CanonicalObjectivePayload::hash($payload);
        $existing = ObjectiveVersion::query()->where('workspace_id', $workspace->id)->where('objective_id', $objective->id)
            ->where('schema_version', self::SCHEMA)->where('content_hash', $hash)->first();
        if ($existingOnly && $existing === null) {
            $this->reject('The saved campaign context changed. Prepare a new review.');
        }

        return $existing ?? ObjectiveVersion::query()->create([
            ...$payload, 'workspace_id' => $workspace->id, 'objective_id' => $objective->id,
            'version' => (int) $objective->versions()->max('version') + 1, 'content_hash' => $hash, 'created_by_user_id' => $actor->id,
        ]);
        // Deliberately never changes Objective.current_version_id/current_plan_id.
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['campaign_context' => $message]);
    }
}
