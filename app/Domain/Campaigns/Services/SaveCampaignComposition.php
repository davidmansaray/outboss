<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\ExecutionAuthorityScope;
use App\Models\ObjectiveGoalVersion;
use App\Models\OfferVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveCampaignComposition
{
    /** @param array<string, mixed> $input */
    public function handle(Campaign $campaign, User $actor, array $input): CampaignComposition
    {
        $workspace = $actor->currentWorkspace();
        if ((int) $campaign->getAttribute('workspace_id') !== (int) $workspace->id || ! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the current workspace owner can compose a campaign.');
        }
        $data = Validator::make($input, [
            'objective_goal_version_id' => ['present', 'nullable', 'uuid'],
            'offer_version_id' => ['present', 'nullable', 'uuid'],
            'execution_authority_scope_id' => ['sometimes', 'nullable', 'uuid'],
            'target_contribution_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'sending_starts_at' => ['sometimes', 'nullable', 'date'],
            'sending_ends_at' => ['sometimes', 'nullable', 'date'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();
        foreach (['sending_starts_at', 'sending_ends_at'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = Carbon::parse($data[$field])->utc()->startOfSecond()->toAtomString();
            }
        }
        $hash = CanonicalObjectivePayload::hash([
            'objective_goal_version_id' => $data['objective_goal_version_id'],
            'offer_version_id' => $data['offer_version_id'],
            'expected_revision' => (int) $data['expected_revision'],
            ...array_intersect_key($data, array_flip(['execution_authority_scope_id', 'target_contribution_count', 'sending_starts_at', 'sending_ends_at'])),
        ]);

        return DB::transaction(function () use ($campaign, $actor, $data, $hash): CampaignComposition {
            $locked = Campaign::query()->where('workspace_id', $campaign->getAttribute('workspace_id'))
                ->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $revisions = CampaignComposition::query()->where('workspace_id', $locked->getAttribute('workspace_id'))->where('campaign_id', $locked->id);
            $replay = (clone $revisions)->where('operation_id', $data['operation_id'])->first();
            if ($replay !== null) {
                if (! hash_equals($replay->request_hash, $hash)) {
                    throw ValidationException::withMessages(['operation_id' => 'This save request already has different content.']);
                }

                return $replay;
            }
            if ($locked->getAttribute('status') !== CampaignStatus::Draft || $locked->launched_at !== null) {
                throw ValidationException::withMessages(['campaign' => 'Only an unlaunched draft can change its composition.']);
            }
            app(GovernedCampaignInputGuard::class)->assertCampaignMutable($locked);
            app(FrozenMailExperimentInputGuard::class)->assertCampaignMutable($locked);
            $current = (clone $revisions)->orderByDesc('revision')->first();
            if (($current->revision ?? 0) !== (int) $data['expected_revision']) {
                throw ValidationException::withMessages(['expected_revision' => 'Campaign selections changed. Reload before saving.']);
            }
            foreach (['objective_goal_version_id' => ObjectiveGoalVersion::class, 'offer_version_id' => OfferVersion::class] as $key => $model) {
                if ($data[$key] !== null && ! $model::query()->where('workspace_id', $locked->getAttribute('workspace_id'))->whereKey($data[$key])->exists()) {
                    throw ValidationException::withMessages([$key => 'Choose a version from this workspace.']);
                }
            }
            $scopeId = array_key_exists('execution_authority_scope_id', $data) ? $data['execution_authority_scope_id'] : $current?->execution_authority_scope_id;
            if ($scopeId !== null && ! ExecutionAuthorityScope::query()->where('workspace_id', $locked->getAttribute('workspace_id'))->whereKey($scopeId)->exists()) {
                throw ValidationException::withMessages(['execution_authority_scope_id' => 'Choose an execution allowance from this workspace.']);
            }
            $start = array_key_exists('sending_starts_at', $data) ? $data['sending_starts_at'] : $current?->sending_starts_at?->toAtomString();
            $end = array_key_exists('sending_ends_at', $data) ? $data['sending_ends_at'] : $current?->sending_ends_at?->toAtomString();
            $startAt = $start === null ? now() : Carbon::parse($start);
            if ($end !== null && ! Carbon::parse($end)->greaterThan($startAt)) {
                throw ValidationException::withMessages(['sending_ends_at' => 'Choose a deadline after the campaign starts.']);
            }
            if ($scopeId !== null) {
                $scope = ExecutionAuthorityScope::query()->where('workspace_id', $locked->getAttribute('workspace_id'))->whereKey($scopeId)->firstOrFail();
                if (($start !== null && ($startAt->lessThan(Carbon::parse($scope->authority['starts_at']))
                        || ! $startAt->lessThan(Carbon::parse($scope->authority['ends_at']))))
                    || ($end !== null && (Carbon::parse($end)->greaterThan(Carbon::parse($scope->authority['ends_at']))
                        || ! Carbon::parse($end)->greaterThan(Carbon::parse($scope->authority['starts_at']))))) {
                    throw ValidationException::withMessages(['sending_ends_at' => 'The scheduled dates must fit within the selected sending allowance.']);
                }
            }

            return CampaignComposition::query()->create([
                'workspace_id' => $locked->getAttribute('workspace_id'), 'campaign_id' => $locked->id,
                'revision' => ($current->revision ?? 0) + 1,
                'objective_goal_version_id' => $data['objective_goal_version_id'],
                'offer_version_id' => $data['offer_version_id'],
                'execution_authority_scope_id' => $scopeId,
                'target_contribution_count' => array_key_exists('target_contribution_count', $data) ? $data['target_contribution_count'] : $current?->target_contribution_count,
                'sending_starts_at' => $start, 'sending_ends_at' => $end,
                'operation_id' => $data['operation_id'], 'request_hash' => $hash,
                'created_by_user_id' => $actor->id,
            ]);
        }, 3);
    }
}
