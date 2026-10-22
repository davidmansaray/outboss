<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;

class ResolveStepSubject
{
    /**
     * Preview and test sends inherit a reply's subject from the nearest earlier
     * new-thread step template. Production replies take the subject from the
     * actual parent message (ScheduleDueCampaignSends); those agree on the
     * normal path, and preview does not fabricate sent messages.
     */
    public function handle(SequenceStep $step): ?string
    {
        $source = $this->source($step);
        $subject = $source->getAttribute('subject_template');

        return is_string($subject) || $subject === null ? $subject : (string) $subject;
    }

    /**
     * Return the step that owns the subject template. Variation rendering uses
     * this step's stable position, so a reply preview agrees with the subject
     * that production already placed on the parent thread.
     */
    public function source(SequenceStep $step): SequenceStep
    {
        $sendAs = $step->getAttribute('send_as');

        if ($sendAs !== SequenceSendAs::Reply && $sendAs !== SequenceSendAs::Reply->value) {
            return $step;
        }

        $versionId = $step->getAttribute('sequence_version_id');
        $version = is_numeric($versionId)
            ? SequenceVersion::query()->whereKey($versionId)->first()
            : null;
        $parent = $version?->activeSteps()
            ->where('send_as', SequenceSendAs::NewThread)
            ->where('position', '<', $step->getAttribute('position'))
            ->orderByDesc('position')
            ->first();

        return $parent instanceof SequenceStep ? $parent : $step;
    }
}
