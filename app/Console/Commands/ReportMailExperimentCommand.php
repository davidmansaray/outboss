<?php

namespace App\Console\Commands;

use App\Domain\Mail\Services\ExperimentEvidenceExporter;
use App\Domain\Mail\Services\ExperimentMetricsService;
use App\Domain\Mail\Services\ManagedMailCapability;
use App\Models\MailExperiment;

class ReportMailExperimentCommand extends ManagedMailOperatorCommand
{
    protected $signature = 'mail-experiment:report
        {experiment : Mail experiment ID}
        {--workspace= : Required workspace ID}
        {--actor= : Optional verified workspace owner user ID for audit attribution}
        {--json : Emit machine-readable output}
        {--format=table : Output format: table, json, or csv}
        {--output= : Write the selected export to this path}';

    protected $description = 'Report recipient-level outcomes and evidence for a mail experiment';

    public function handle(
        ExperimentMetricsService $metrics,
        ExperimentEvidenceExporter $exporter,
        ManagedMailCapability $capability,
    ): int {
        $context = $this->operatorContext($capability);
        if ($context === null) {
            return self::FAILURE;
        }

        $workspaceId = $context['workspace']->id;

        $experiment = MailExperiment::query()
            ->whereKey($this->argument('experiment'))
            ->where('workspace_id', $workspaceId)
            ->first();
        if (! $experiment) {
            $this->error('Mail experiment not found in the requested workspace.');

            return self::FAILURE;
        }

        try {
            $format = strtolower((string) $this->option('format'));
            $content = match ($format) {
                'json' => $exporter->toJson($experiment),
                'csv' => $exporter->toCsv($experiment),
                'table' => null,
                default => throw new \InvalidArgumentException('Format must be table, json, or csv.'),
            };

            $output = $this->option('output');
            if ($content !== null) {
                if (is_string($output) && $output !== '') {
                    if (file_put_contents($output, $content) === false) {
                        throw new \RuntimeException('Unable to write report output.');
                    }
                    $this->info('Wrote '.$format.' report to '.$output.'.');
                } else {
                    $this->line($content);
                }

                return self::SUCCESS;
            }

            $report = $metrics->report($experiment);
            $summary = $report['summary'];
            $this->table(
                ['metric', 'value'],
                [
                    ['assignments', $summary['assignment_count']],
                    ['excluded', $summary['excluded_count']],
                    ['provider-submitted first messages', $summary['exposed_count']],
                    ['human replies', $summary['human_reply_count']],
                    ['human reply rate', $this->percent($summary['human_reply_rate'])],
                    ['interested replies', $summary['interested_count']],
                    ['bounced', $summary['bounced_count']],
                    ['unsubscribed', $summary['unsubscribed_count']],
                ],
            );
            foreach ($report['warnings'] as $warning) {
                $this->warn($warning);
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function percent(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value * 100, 2).'%' : 'n/a';
    }
}
