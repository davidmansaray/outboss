<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use App\Domain\Objectives\Enums\AudienceMemberResult;
use App\Domain\Objectives\Support\AudiencePreviewToken;
use App\Models\AudienceSnapshot;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\CampaignRecipient;
use App\Models\ObjectiveGoalVersion;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal compiler step. The enclosing compiler owns idempotency and exact approval. */
final class FreezeHumanCampaignAudience
{
    public function handle(Campaign $campaign, CampaignComposition $composition, User $actor): AudienceSnapshot
    {
        $workspace = $actor->currentWorkspace();
        abort_unless((int) $campaign->getAttribute('workspace_id') === (int) $workspace->id, 404);
        abort_unless($workspace->isOwner($actor), 403);

        return DB::transaction(function () use ($campaign, $composition, $actor, $workspace): AudienceSnapshot {
            $campaign = Campaign::query()->where('workspace_id', $workspace->id)->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            app(GovernedCampaignInputGuard::class)->assertCampaignMutable($campaign);
            app(FrozenMailExperimentInputGuard::class)->assertCampaignMutable($campaign);
            $current = CampaignComposition::query()->where('workspace_id', $workspace->id)->where('campaign_id', $campaign->id)
                ->orderByDesc('revision')->lockForUpdate()->first();
            if ($current === null || $current->id !== $composition->id
                || $current->objective_goal_version_id === null || $current->offer_version_id === null
                || $campaign->getAttribute('status') !== CampaignStatus::Draft || $campaign->launched_at !== null
                || $campaign->published_sequence_version_id !== null) {
                $this->reject('Use the current complete composition of an unlaunched campaign draft.');
            }
            $goal = ObjectiveGoalVersion::query()->where('workspace_id', $workspace->id)
                ->whereKey($current->objective_goal_version_id)->firstOrFail();
            $recipients = CampaignRecipient::query()->where('workspace_id', $workspace->id)->where('campaign_id', $campaign->id)
                ->humanReviewAudience()->orderBy('id')->lockForUpdate()->get();
            if ($recipients->isEmpty() || $recipients->count() > (int) config('objectives.safety.maximum_people')
                || $recipients->contains(fn ($recipient): bool => $recipient->person_id === null || $recipient->account_id === null
                    || $recipient->getAttribute('status') !== RecipientStatus::Queued || $recipient->first_sent_at !== null
                    || $recipient->send_lease_token !== null)) {
                $this->reject('Select a bounded audience of queued prospects with Account identities before preparing review.');
            }
            $ids = $recipients->pluck('person_id')->map(strval(...))->sort()->values()->all();
            $people = Person::query()->where('workspace_id', $workspace->id)->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            if (count(array_unique($ids)) !== count($ids) || $people->count() !== count($ids)) {
                $this->reject('Every recipient must identify one distinct prospect in this workspace.');
            }
            foreach ($recipients as $recipient) {
                app(AssertHumanRecipientContactable::class)->handle($recipient);
                if ($recipient->sendAttempts()->exists()) {
                    $this->reject('A selected recipient already has a send attempt. Resolve its delivery status before preparing another review.');
                }
                if ((int) $people->get($recipient->person_id)->account_id !== (int) $recipient->account_id) {
                    $this->reject('A selected prospect changed Account. Refresh the campaign audience.');
                }
            }
            // Human selection is deliberate; research is optional. The shared
            // evaluator still enforces email validity, source rights and suppression.
            $segment = app(CreateSegment::class)->handle($workspace, $actor,
                'Campaign audience — '.mb_substr((string) $campaign->name, 0, 130),
                ['all' => [
                    ['field' => 'person.id', 'operator' => 'in', 'value' => $ids],
                ]],
                'Internal exact selection for human campaign review; not a prospect search.',
            );
            $version = $segment->currentVersion;
            $evaluation = app(EvaluateSegment::class)->handle($workspace, $version);
            $included = collect($evaluation->members)->filter(fn (array $row): bool => $row['result'] === AudienceMemberResult::Included->value)
                ->pluck('person_id')->map(strval(...))->sort()->values()->all();
            if ($included !== $ids) {
                $evaluated = collect($evaluation->members)->keyBy('person_id');
                $unavailable = $recipients->filter(fn ($recipient): bool => ! in_array((string) $recipient->person_id, $included, true))
                    ->take(5)->map(function ($recipient) use ($evaluated): string {
                        $row = $evaluated->get($recipient->person_id);
                        $reason = match ($row['exclusion_reasons'][0] ?? null) {
                            'currently_suppressed' => 'suppressed; remove this prospect from the campaign',
                            'email_missing_or_invalid' => 'correct the prospect email before review',
                            'source_rights_not_allowed' => 'source rights do not currently permit outreach',
                            default => 'no eligible source record; review this prospect’s source before sending',
                        };

                        return $recipient->email_address.': '.$reason;
                    })->implode('; ');
                $this->reject('Every selected prospect must pass current email, source-rights and suppression checks. No recipients were removed. '.$unavailable);
            }
            $token = app(AudiencePreviewToken::class)->issue((int) $workspace->id, (string) $version->id, $evaluation->evaluationHash);

            return app(FreezeAudienceSnapshot::class)->handle($workspace, $version, $actor,
                $evaluation->evaluationHash, $token, (string) $goal->objective_id);
        }, 3);
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['audience' => $message]);
    }
}
