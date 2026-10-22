<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseName;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\Objective;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Internal preparation step: never dispatches AI work or authorizes sending. */
final class StartHumanCampaignRun
{
    public function handle(Campaign $campaign, CampaignComposition $composition, ObjectiveVersion $context, User $actor, string $operationId): ObjectiveRun
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Human run creation requires the enclosing preparation transaction.');
        }
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'A unique preparation request is required.']);
        }
        $workspace = $actor->currentWorkspace();
        abort_unless((int) $campaign->getAttribute('workspace_id') === (int) $workspace->id, 404);
        abort_unless($workspace->isOwner($actor), 403);
        $context = ObjectiveVersion::query()->where('workspace_id', $workspace->id)->whereKey($context->id)->firstOrFail();
        if ($context->schema_version !== FreezeHumanCampaignContext::SCHEMA
            || ($context->authority_envelope['campaign_composition_id'] ?? null) !== $composition->id) {
            throw ValidationException::withMessages(['campaign_context' => 'Use the exact frozen human campaign context.']);
        }
        $verified = app(FreezeHumanCampaignContext::class)->handle($campaign, $composition, $context->audienceSnapshot, $actor);
        if ($verified->id !== $context->id || ! hash_equals($verified->content_hash, $context->content_hash)) {
            throw ValidationException::withMessages(['campaign_context' => 'The campaign context changed. Prepare it again.']);
        }
        $objective = Objective::query()->where('workspace_id', $workspace->id)->whereKey($context->objective_id)->lockForUpdate()->firstOrFail();
        $keyHash = hash('sha256', 'human_campaign:'.$workspace->id.'|'.$operationId);
        $inputHash = CanonicalObjectivePayload::hash([
            'schema' => 'human_campaign_run_v1', 'campaign_id' => $campaign->id,
            'campaign_composition_id' => $composition->id, 'context_id' => $context->id,
            'context_hash' => $context->content_hash, 'author_id' => $actor->id,
        ]);
        $existing = ObjectiveRun::query()->where('workspace_id', $workspace->id)->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->getAttribute('origin') !== ExecutionOrigin::Human || ! hash_equals($existing->input_hash, $inputHash)) {
                throw ValidationException::withMessages(['operation_id' => 'This preparation request already identifies different inputs.']);
            }

            return $existing;
        }
        $active = ObjectiveRun::query()->where('workspace_id', $workspace->id)->where('origin', 'human')
            ->whereIn('campaign_composition_id', CampaignComposition::query()->where('workspace_id', $workspace->id)->where('campaign_id', $campaign->id)->select('id'))
            ->whereIn('state', [ObjectiveRunState::Planned, ObjectiveRunState::Running, ObjectiveRunState::Waiting, ObjectiveRunState::NeedsReview, ObjectiveRunState::Paused])
            ->lockForUpdate()->first();
        if ($active !== null) {
            throw ValidationException::withMessages(['campaign' => 'This campaign already has an unfinished preparation. Resume or cancel it before preparing another.']);
        }

        return ObjectiveRun::query()->create([
            'origin' => ExecutionOrigin::Human, 'campaign_composition_id' => $composition->id,
            'workspace_id' => $workspace->id, 'objective_id' => $objective->id, 'objective_version_id' => $context->id,
            'audience_snapshot_id' => $context->audience_snapshot_id, 'gtm_experiment_id' => null,
            'run_number' => (int) $objective->runs()->max('run_number') + 1,
            'state' => ObjectiveRunState::Planned, 'current_phase' => ObjectiveRunPhaseName::DraftMessages,
            'input_hash' => $inputHash, 'idempotency_key_hash' => $keyHash, 'schema_version' => 'human_campaign_run_v1',
            'message_validator_version' => null, 'maximum_cost_micros' => 0, 'reserved_cost_micros' => 0, 'actual_cost_micros' => 0,
            'ai_budget_envelope_id' => null, 'created_by_user_id' => $actor->id,
        ]);
    }
}
