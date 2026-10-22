<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Enums\SequenceVersionStatus;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use App\Models\Campaign;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SequenceStepEditor
{
    public function __construct(
        private PublishSequenceVersion $versions,
        private FrozenMailExperimentInputGuard $frozenInputs,
        private GovernedCampaignInputGuard $governedInputs,
    ) {}

    public function ensureDraft(Campaign $campaign): void
    {
        $this->governedInputs->assertCampaignMutable($campaign);
        $this->frozenInputs->assertCampaignMutable($campaign);

        $status = $campaign->getAttribute('status');
        if (! $status instanceof CampaignStatus) {
            $status = CampaignStatus::from((string) $status);
        }

        if (! $status->allowsEditing()) {
            throw new RuntimeException('campaign_not_editable');
        }

        $draft = $campaign->draftVersion;
        $draftStatus = $draft?->getAttribute('status');
        if ($draftStatus !== null && ! $draftStatus instanceof SequenceVersionStatus) {
            $draftStatus = SequenceVersionStatus::from((string) $draftStatus);
        }

        if ($status === CampaignStatus::Paused && $draftStatus !== SequenceVersionStatus::Draft) {
            $this->versions->cloneDraft($campaign);
            $campaign->refresh();
        }
    }

    public function add(Campaign $campaign, string $title = 'Follow-up'): SequenceStep
    {
        $this->ensureDraft($campaign);

        return DB::transaction(function () use ($campaign, $title): SequenceStep {
            $draftId = $campaign->fresh()->getAttribute('draft_sequence_version_id');
            $version = is_numeric($draftId)
                ? SequenceVersion::query()->whereKey($draftId)->lockForUpdate()->first()
                : null;

            if (! $version instanceof SequenceVersion) {
                throw new RuntimeException('sequence_stale');
            }

            $position = (int) $version->steps()->max('position') + 1;

            return SequenceStep::query()->create([
                'sequence_version_id' => $version->id,
                'position' => $position,
                'title' => $this->nextAvailableTitle($version, $title),
                'delay_minutes' => 3 * 24 * 60,
                'send_as' => SequenceSendAs::Reply,
                'subject_template' => null,
                'body_template' => 'Bumping this once.',
            ]);
        });
    }

    private function nextAvailableTitle(SequenceVersion $version, string $title): string
    {
        $titles = $version->activeSteps()->pluck('title');

        if (! $titles->contains($title)) {
            return $title;
        }

        $highest = $titles->reduce(function (int $highest, string $candidate) use ($title): int {
            if (! preg_match('/\s(\d+)$/', $candidate, $matches)) {
                return $highest;
            }

            $number = (int) $matches[1];
            $suffix = ' '.$number;
            $expected = mb_substr($title, 0, 80 - mb_strlen($suffix)).$suffix;

            return $candidate === $expected ? max($highest, $number) : $highest;
        }, 1);
        $suffix = ' '.($highest + 1);

        return mb_substr($title, 0, 80 - mb_strlen($suffix)).$suffix;
    }

    public function update(SequenceStep $step, array $data): SequenceStep
    {
        $step = $this->draftStep($step);

        // Saving copy must not silently re-enable a user's disabled variations.
        $step->forceFill($data)->save();

        return $step->fresh();
    }

    public function delete(SequenceStep $step): void
    {
        $step = $this->draftStep($step);
        $step->forceFill(['deleted_at' => now()])->save();
    }

    /**
     * @param  list<int>  $stepIds
     */
    public function reorder(Campaign $campaign, array $stepIds): void
    {
        $this->ensureDraft($campaign);
        $campaign->refresh();
        $draftId = $campaign->getAttribute('draft_sequence_version_id');
        $version = is_numeric($draftId)
            ? SequenceVersion::query()->whereKey($draftId)->first()
            : null;

        if (! $version instanceof SequenceVersion) {
            throw new RuntimeException('sequence_stale');
        }

        $requested = $this->resolveDraftStepIds($campaign, $stepIds);

        DB::transaction(function () use ($version, $requested): void {
            $max = max(1, (int) $version->steps()->max('position'));
            $version->steps()->increment('position', $max);

            $position = 1;

            foreach ($requested as $id) {
                SequenceStep::query()->whereKey($id)->update(['position' => $position]);
                $position++;
            }

            $deleted = $version->steps()
                ->whereNotNull('deleted_at')
                ->orderBy('position')
                ->get();

            foreach ($deleted as $step) {
                $step->forceFill(['position' => $position])->save();
                $position++;
            }
        });
    }

    /**
     * @param  list<int>  $stepIds
     * @return list<int>
     */
    private function resolveDraftStepIds(Campaign $campaign, array $stepIds): array
    {
        $draftId = $campaign->getAttribute('draft_sequence_version_id');
        $version = is_numeric($draftId)
            ? SequenceVersion::query()->whereKey($draftId)->first()
            : null;

        if (! $version instanceof SequenceVersion) {
            throw new RuntimeException('sequence_stale');
        }

        $requested = array_map(intval(...), $stepIds);
        $draftSteps = $version->activeSteps()->get();
        $activeIds = $draftSteps->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        if ($this->sameIdSet($requested, $activeIds)) {
            return $requested;
        }

        $publishedId = $campaign->getAttribute('published_sequence_version_id');
        $published = is_numeric($publishedId)
            ? SequenceVersion::query()->whereKey($publishedId)->first()
            : null;

        if (! $published instanceof SequenceVersion || (int) $published->getKey() === (int) $version->getKey()) {
            throw new RuntimeException('sequence_stale');
        }

        $publishedSteps = $published->activeSteps()->get();
        $publishedIds = $publishedSteps->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        if (! $this->sameIdSet($requested, $publishedIds)) {
            throw new RuntimeException('sequence_stale');
        }

        $publishedById = $publishedSteps->keyBy('id');
        $draftByPosition = $draftSteps->keyBy('position');
        $mapped = [];

        foreach ($requested as $id) {
            $source = $publishedById->get($id);
            $draft = $source instanceof SequenceStep
                ? $draftByPosition->get((int) $source->getAttribute('position'))
                : null;

            if (! $draft instanceof SequenceStep) {
                throw new RuntimeException('sequence_stale');
            }

            $mapped[] = (int) $draft->getKey();
        }

        return $mapped;
    }

    /**
     * @param  array<int, int>  $left
     * @param  array<int, int>  $right
     */
    private function sameIdSet(array $left, array $right): bool
    {
        $sortedLeft = $left;
        $sortedRight = $right;
        sort($sortedLeft);
        sort($sortedRight);

        return $sortedLeft === $sortedRight;
    }

    private function draftStep(SequenceStep $step): SequenceStep
    {
        $campaign = $step->version->campaign;
        $this->ensureDraft($campaign);
        $campaign->refresh();

        if ($step->version->isImmutable()) {
            $candidate = $campaign->draftVersion?->activeSteps()
                ->where('position', $step->position)
                ->first();

            if ($candidate instanceof SequenceStep && $this->looksLikeClone($candidate, $step)) {
                return $candidate;
            }

            throw new RuntimeException('step_immutable');
        }

        return $step;
    }

    private function looksLikeClone(SequenceStep $draft, SequenceStep $source): bool
    {
        return $draft->title === $source->title
            && (int) $draft->delay_minutes === (int) $source->delay_minutes
            && $draft->send_as === $source->send_as
            && $draft->subject_template === $source->subject_template
            && $draft->body_template === $source->body_template
            && $draft->subject_spintax_enabled === $source->subject_spintax_enabled
            && $draft->body_spintax_enabled === $source->body_spintax_enabled;
    }
}
