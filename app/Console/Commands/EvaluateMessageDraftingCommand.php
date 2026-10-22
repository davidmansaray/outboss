<?php

namespace App\Console\Commands;

use App\Domain\Objectives\Exceptions\MessageDraftingEvaluationException;
use App\Domain\Objectives\Services\RunMessageDraftingEvaluation;
use Illuminate\Console\Command;

final class EvaluateMessageDraftingCommand extends Command
{
    protected $signature = 'odg-s3:evaluate-message-drafting
        {--dataset=v1 : Checked-in versioned evaluation dataset}
        {--responses=deterministic : Offline checked-in response replay}
        {--json : Emit privacy-safe machine-readable output}';

    protected $description = 'Replay the offline S3 message-drafting evaluation corpus';

    public function handle(RunMessageDraftingEvaluation $evaluation): int
    {
        $dataset = $this->option('dataset');
        $responses = $this->option('responses');
        if (! is_string($dataset) || ! is_string($responses)) {
            return $this->invalidInvocation('Evaluation options must be strings.');
        }

        try {
            $report = $evaluation->handle($dataset, $responses);
        } catch (MessageDraftingEvaluationException $exception) {
            if ($this->option('json')) {
                $this->line((string) json_encode([
                    'error' => [
                        'code' => $exception->safeCode,
                        'message' => $exception->getMessage(),
                    ],
                    'release_gate_status' => 'invalid',
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->error($exception->getMessage());
            }

            return self::INVALID;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            ));
        } else {
            $this->renderSummary($report);
        }

        return ($report['release_gate_status'] ?? null) === 'passed'
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function invalidInvocation(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'error' => ['code' => 'invocation_invalid', 'message' => $message],
                'release_gate_status' => 'invalid',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return self::INVALID;
    }

    /** @param array<string, mixed> $report */
    private function renderSummary(array $report): void
    {
        $this->line(sprintf(
            'Message-drafting evaluation %s: %s (%d synthetic cases).',
            (string) $report['dataset_version'],
            strtoupper((string) $report['release_gate_status']),
            (int) $report['case_count'],
        ));
        $rows = [];
        foreach ($report['metrics'] as $name => $metric) {
            $value = $metric['value'];
            $rows[] = [
                $name,
                $value === null ? 'n/a' : (is_float($value) ? number_format($value, 4) : (string) $value),
                $metric['numerator'].'/'.$metric['denominator'],
            ];
        }
        $this->table(['Metric', 'Value', 'Numerator/denominator'], $rows);
        $this->warn('Release gate blocked: '.implode(', ', $report['release_gate_reasons']).'.');
        $this->line('Evidence provenance: deterministic_fixture (not target-provider evidence).');
    }
}
