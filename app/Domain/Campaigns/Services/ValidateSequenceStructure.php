<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\DTOs\SequenceIssue;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Models\SequenceVersion;
use InvalidArgumentException;

class ValidateSequenceStructure
{
    /**
     * @return list<SequenceIssue>
     */
    public function handle(?SequenceVersion $version): array
    {
        if (! $version instanceof SequenceVersion) {
            return [new SequenceIssue('empty', 'Add an initial email.')];
        }

        $steps = $version->activeSteps()->get();

        if ($steps->isEmpty()) {
            return [new SequenceIssue('empty', 'Add an initial email.')];
        }

        $issues = [];
        $first = $steps->first();

        if (
            (int) $first->getAttribute('delay_minutes') !== 0
            || ! $this->isSendAs($first->getAttribute('send_as'), SequenceSendAs::NewThread)
        ) {
            $issues[] = new SequenceIssue(
                'first_step_not_immediate_new_thread',
                'The first step must be an immediate new thread.',
            );
        }

        $hasParent = false;

        foreach ($steps as $step) {
            if (trim((string) $step->getAttribute('body_template')) === '') {
                $issues[] = new SequenceIssue('missing_body', 'Every step needs a body.');
            }

            if (
                $this->isSendAs($step->getAttribute('send_as'), SequenceSendAs::NewThread)
                && trim((string) $step->getAttribute('subject_template')) === ''
            ) {
                $issues[] = new SequenceIssue('missing_subject', 'New-thread steps need a subject.');
            }

            if ($this->isSendAs($step->getAttribute('send_as'), SequenceSendAs::Reply) && ! $hasParent) {
                $issues[] = new SequenceIssue(
                    'reply_without_parent',
                    'Reply steps need an earlier new-thread step.',
                );
            }

            if ($this->isSendAs($step->getAttribute('send_as'), SequenceSendAs::NewThread)) {
                $hasParent = true;
            }

            $content = (string) $step->getAttribute('body_template').' '.(string) $step->getAttribute('subject_template');

            if (preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $content, $matches)) {
                foreach ($matches[1] as $variable) {
                    if (! in_array($variable, RenderSequenceMessage::SUPPORTED_VARIABLES, true)) {
                        $issues[] = new SequenceIssue(
                            'unknown_variable',
                            'Unknown variable {{'.$variable.'}}.',
                        );
                    }
                }
            }

            foreach ([
                'subject_template' => 'subject_spintax_enabled',
                'body_template' => 'body_spintax_enabled',
            ] as $attribute => $enabledAttribute) {
                if ((bool) $step->getAttribute($enabledAttribute)) {
                    try {
                        app(SpintaxTemplate::class)->validate((string) $step->getAttribute($attribute));
                    } catch (InvalidArgumentException $exception) {
                        $issues[] = new SequenceIssue('invalid_spintax', $exception->getMessage());
                    }
                }
            }
        }

        return $issues;
    }

    private function isSendAs(mixed $sendAs, SequenceSendAs $expected): bool
    {
        return $sendAs === $expected || $sendAs === $expected->value;
    }
}
