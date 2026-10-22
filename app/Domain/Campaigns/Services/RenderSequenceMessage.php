<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Exceptions\InvalidSequenceTemplate;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\CampaignRecipient;
use App\Models\SequenceStep;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RenderSequenceMessage
{
    public function __construct(private SpintaxTemplate $spintax) {}

    /**
     * @var list<string>
     */
    public const SUPPORTED_VARIABLES = ['first_name', 'company'];

    /**
     * @return array{subject: string, body: string}
     */
    public function handle(
        SequenceStep $step,
        CampaignRecipient $recipient,
        bool $test = false,
        ?SequenceStep $subjectSeedStep = null,
        ?string $recipientSeed = null,
    ): array {
        $governed = $this->linkedGovernedSnapshot($step, $recipient);
        if ($governed !== null) {
            return $governed;
        }

        $values = [
            'first_name' => $test ? 'Jane' : ($recipient->first_name ?: 'there'),
            'company' => $test ? 'Acme' : ($recipient->company ?: 'your team'),
        ];

        $subjectStep = $subjectSeedStep ?? $step;
        $subject = $this->renderField(
            (string) $step->subject_template,
            $values,
            $this->variationSeed($subjectStep, $recipient, 'subject', $recipientSeed),
            $subjectStep,
            'subject_template',
        );
        $body = $this->renderField(
            $step->body_template,
            $values,
            $this->variationSeed($step, $recipient, 'body', $recipientSeed),
            $step,
            'body_template',
        );

        if ($test) {
            $subject = '[TEST] '.$subject;
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * Narrow pre-link verification boundary used inside the compiler's outer
     * transaction. These bytes remain unavailable to normal rendering until
     * the recipient has an immutable successful action-compilation link.
     *
     * @return array{subject: string, body: string}
     */
    public function verifyGovernedSnapshot(SequenceStep $step, CampaignRecipient $recipient): array
    {
        if ((int) $recipient->getAttribute('sequence_version_id') !== (int) $step->getAttribute('sequence_version_id')
            || (int) $recipient->getAttribute('next_sequence_step_id') !== (int) $step->getKey()
            || (bool) $step->getAttribute('subject_spintax_enabled')
            || (bool) $step->getAttribute('body_spintax_enabled')) {
            throw $this->governedContentException($step, 'The governed recipient does not match its fixed no-spintax step.');
        }

        return $this->snapshotBytes($step, $recipient);
    }

    /**
     * @param  array<string, string>  $values
     */
    public function render(string $template, array $values, string $variationSeed = 'sample'): string
    {
        $this->assertKnownVariables($template, $values);
        $template = $this->spintax->render($template, $variationSeed);

        return $this->renderVariables($template, $values);
    }

    /**
     * Render the exact non-spintax bytes used by governed plan previews.
     * The compiler calls this same boundary again after it creates the
     * CampaignRecipient, so approved content cannot drift before launch.
     *
     * @param  array{first_name: string, company: string}  $recipientSnapshot
     * @return array{subject: string, body: string}
     */
    public function previewTemplates(string $subjectTemplate, string $bodyTemplate, array $recipientSnapshot): array
    {
        $values = [
            'first_name' => $recipientSnapshot['first_name'],
            'company' => $recipientSnapshot['company'],
        ];

        return [
            'subject' => $this->render($subjectTemplate, $values),
            'body' => $this->render($bodyTemplate, $values),
        ];
    }

    /** @param array<string, string> $values */
    private function renderField(
        string $template,
        array $values,
        string $variationSeed,
        SequenceStep $sourceStep,
        string $field,
    ): string {
        try {
            $this->assertKnownVariables($template, $values);
            $enabledAttribute = $field === 'subject_template'
                ? 'subject_spintax_enabled'
                : 'body_spintax_enabled';
            $rendered = (bool) $sourceStep->getAttribute($enabledAttribute)
                ? $this->spintax->render($template, $variationSeed)
                : $template;

            return $this->renderVariables($rendered, $values);
        } catch (InvalidArgumentException $exception) {
            $position = max(1, (int) $sourceStep->getAttribute('position'));
            $label = $field === 'subject_template' ? 'subject' : 'body';

            throw new InvalidSequenceTemplate(
                $field,
                (int) $sourceStep->getKey(),
                "Email {$position} {$label}: {$exception->getMessage()}",
                $exception,
            );
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    private function assertKnownVariables(string $template, array $values): void
    {
        if (preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $template, $matches)) {
            foreach ($matches[1] as $variable) {
                if (! array_key_exists($variable, $values)) {
                    throw new InvalidArgumentException('Unknown variable {{'.$variable.'}}');
                }
            }
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    private function renderVariables(string $template, array $values): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function (array $matches) use ($values) {
            if (! array_key_exists($matches[1], $values)) {
                throw new InvalidArgumentException('Unknown variable {{'.$matches[1].'}}');
            }

            return e($values[$matches[1]]);
        }, $template) ?? $template;
    }

    private function variationSeed(
        SequenceStep $step,
        CampaignRecipient $recipient,
        string $field,
        ?string $recipientSeed,
    ): string {
        $campaign = $recipient->getAttribute('campaign_id') ?: 'sample';
        $recipientKey = $recipientSeed ?? ($recipient->getKey()
            ? 'id:'.$recipient->getKey()
            : 'email:'.strtolower(trim((string) $recipient->getAttribute('email_address'))));
        $stepKey = $step->getAttribute('position') ?: $step->getKey() ?: 'sample';

        return 'campaign:'.$campaign.'|recipient:'.$recipientKey.'|step:'.$stepKey.'|field:'.$field;
    }

    /** @return array{subject: string, body: string}|null */
    private function linkedGovernedSnapshot(SequenceStep $step, CampaignRecipient $recipient): ?array
    {
        $subject = $recipient->getAttribute('governed_subject_snapshot');
        $body = $recipient->getAttribute('governed_body_text_snapshot');
        if ($subject === null && $body === null) {
            return null;
        }
        if (! $recipient->exists) {
            throw $this->governedContentException($step, 'The governed recipient snapshot is not persisted.');
        }

        $link = DB::table('execution_plan_action_compilations as action_links')
            ->join('execution_plan_compilations as compilations', function ($join): void {
                $join->on('compilations.id', '=', 'action_links.execution_plan_compilation_id')
                    ->on('compilations.workspace_id', '=', 'action_links.workspace_id');
            })
            ->join('execution_plan_campaigns as campaign_links', function ($join): void {
                $join->on('campaign_links.id', '=', 'action_links.execution_plan_campaign_id')
                    ->on('campaign_links.workspace_id', '=', 'action_links.workspace_id');
            })
            ->join('execution_plan_actions as actions', function ($join): void {
                $join->on('actions.id', '=', 'action_links.execution_plan_action_id')
                    ->on('actions.workspace_id', '=', 'action_links.workspace_id');
            })
            ->where('action_links.workspace_id', $recipient->getAttribute('workspace_id'))
            ->where('action_links.campaign_recipient_id', $recipient->getKey())
            ->where('action_links.sequence_step_id', $step->getKey())
            ->where('campaign_links.campaign_id', $recipient->getAttribute('campaign_id'))
            ->where('compilations.state', 'succeeded')
            ->select([
                'actions.subject',
                'actions.body_text',
                'actions.content_hash',
                'action_links.rendered_content_hash',
            ])
            ->first();
        if ($link === null) {
            throw $this->governedContentException($step, 'The governed recipient has no successful compilation link.');
        }

        $rendered = $this->snapshotBytes($step, $recipient);
        $contentHash = CanonicalObjectivePayload::hash([
            'subject' => $rendered['subject'],
            'body_text' => $rendered['body'],
            'body_html' => null,
        ]);
        if (! is_string($link->subject) || ! is_string($link->body_text)
            || ! hash_equals($link->subject, $rendered['subject'])
            || ! hash_equals($link->body_text, $rendered['body'])
            || ! hash_equals((string) $link->content_hash, $contentHash)
            || ! hash_equals((string) $link->rendered_content_hash, $contentHash)) {
            throw $this->governedContentException($step, 'The governed recipient content no longer matches its approved action.');
        }

        return $rendered;
    }

    /** @return array{subject: string, body: string} */
    private function snapshotBytes(SequenceStep $step, CampaignRecipient $recipient): array
    {
        $subject = $recipient->getAttribute('governed_subject_snapshot');
        $body = $recipient->getAttribute('governed_body_text_snapshot');
        if (! is_string($subject) || $subject === '' || ! is_string($body) || $body === '') {
            throw $this->governedContentException($step, 'The governed recipient content snapshot is incomplete.');
        }

        return ['subject' => $subject, 'body' => $body];
    }

    private function governedContentException(SequenceStep $step, string $message): InvalidSequenceTemplate
    {
        return new InvalidSequenceTemplate(
            'governed_content_snapshot',
            (int) $step->getKey(),
            $message,
        );
    }
}
