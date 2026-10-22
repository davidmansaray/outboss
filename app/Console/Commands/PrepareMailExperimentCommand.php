<?php

namespace App\Console\Commands;

use App\Domain\Mail\DTOs\AssignmentDecision;
use App\Domain\Mail\Services\MailExperimentAssignmentService;
use App\Domain\Mail\Services\MailExperimentLifecycleService;
use App\Domain\Mail\Services\ManagedMailCapability;
use App\Models\Campaign;
use App\Models\MailExperiment;
use App\Models\Person;

class PrepareMailExperimentCommand extends ManagedMailOperatorCommand
{
    protected $signature = 'mail-experiment:prepare
        {experiment : Mail experiment ID}
        {--workspace= : Required workspace ID}
        {--actor= : Optional verified workspace owner user ID for audit attribution}
        {--template-campaign= : Optional template campaign ID to clone}
        {--confirm-compliance-review : Confirm that the prepared audience passed compliance review}
        {--confirm-legal-review : Confirm that the prepared audience passed legal review}
        {--approval-reference= : Optional ticket or review reference stored with the evidence}
        {--dry-run : Preview assignments without persisting them}
        {--json : Emit machine-readable assignment output}';

    protected $description = 'Prepare deterministic, provider-stratified assignments for a mail experiment';

    public function handle(
        MailExperimentAssignmentService $assignments,
        MailExperimentLifecycleService $lifecycle,
        ManagedMailCapability $capability,
    ): int {
        $context = $this->operatorContext($capability);
        if ($context === null) {
            return self::FAILURE;
        }

        $workspaceId = $context['workspace']->id;

        if ($this->option('confirm-compliance-review') !== true
            || $this->option('confirm-legal-review') !== true) {
            $this->error('Both --confirm-compliance-review and --confirm-legal-review are required.');

            return self::INVALID;
        }

        $approvalEvidence = [
            'compliance_review_confirmed' => true,
            'legal_review_confirmed' => true,
            'source' => 'cli',
            'confirmed_by' => get_current_user(),
        ];
        $approvalReference = $this->option('approval-reference');
        if (is_string($approvalReference) && trim($approvalReference) !== '') {
            $approvalEvidence['reference'] = $approvalReference;
        }

        $experiment = MailExperiment::query()
            ->whereKey($this->argument('experiment'))
            ->where('workspace_id', $workspaceId)
            ->first();
        if (! $experiment) {
            $this->error('Mail experiment not found in the requested workspace.');

            return self::FAILURE;
        }

        $candidates = Person::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->get();

        try {
            if ($this->option('dry-run')) {
                $decisions = $assignments->preview($experiment, $candidates, $approvalEvidence);
                $payload = [
                    'experiment_id' => $experiment->id,
                    'workspace_id' => $workspaceId,
                    'assignments' => array_map(fn ($decision): array => $decision->toArray(), $decisions),
                ];

                if ($this->option('json')) {
                    $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    $this->renderPreview($decisions);
                }

                return self::SUCCESS;
            }

            $template = null;
            $templateId = $this->option('template-campaign');
            if ($templateId !== null && $templateId !== '') {
                $template = Campaign::query()
                    ->whereKey($templateId)
                    ->where('workspace_id', $workspaceId)
                    ->first();
                if (! $template) {
                    $this->error('Template campaign not found in the requested workspace.');

                    return self::FAILURE;
                }
            }

            $prepared = $lifecycle->prepare($experiment, $candidates, $template, $approvalEvidence);
            $payload = [
                'experiment_id' => $prepared->id,
                'workspace_id' => $workspaceId,
                'status' => $prepared->statusValue(),
                'assignment_count' => $prepared->assignments()->count(),
                'excluded_count' => $prepared->assignments()->whereNotNull('exclusion_reason')->count(),
            ];

            if ($this->option('json')) {
                $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->info('Prepared '.$payload['assignment_count'].' assignment(s), including '.$payload['excluded_count'].' exclusion(s).');
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param list<AssignmentDecision> $decisions */
    private function renderPreview(array $decisions): void
    {
        $rows = [];
        foreach ($decisions as $decision) {
            $rows[] = [
                $decision->emailNormalized,
                $decision->recipientProvider->value,
                $decision->stratumKey,
                $decision->cohortKey ?? 'excluded',
                $decision->exclusionReason ?? '',
            ];
        }

        $this->table(['email', 'provider', 'stratum', 'cohort', 'exclusion'], $rows);
    }
}
