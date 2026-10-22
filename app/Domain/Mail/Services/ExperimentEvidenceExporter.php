<?php

namespace App\Domain\Mail\Services;

use App\Domain\Workspaces\Support\CsvExport;
use App\Models\MailExperiment;
use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExperimentEvidenceExporter
{
    /** @var list<string> */
    private const HEADERS = [
        'assignment_id',
        'email_normalized',
        'recipient_domain',
        'recipient_provider',
        'stratum_key',
        'assignment_key',
        'cohort_key',
        'cohort_label',
        'campaign_id',
        'campaign_recipient_id',
        'mailbox_key',
        'mailbox',
        'sender_domain',
        'first_attempt_status',
        'provider_attempted_at',
        'provider_submitted_first_message',
        'exposed',
        'attempted',
        'attempt_count',
        'excluded',
        'exclusion_reason',
        'human_reply',
        'interested',
        'bounced',
        'first_message_hard_bounce',
        'unsubscribed',
        'unknown_first_attempt',
        'manual_review_first_attempt',
        'provider_unknown_count',
        'provider_manual_review_count',
        'provider_error_count',
        'provider_error_attempts',
        'unknown_manual_review_attempts',
        'mailbox_health_paused',
        'sync_failure_count',
        'soft_bounce',
        'first_sent_at',
        'replied_at',
        'reply_seconds',
        'follow_up_stop_correct',
        'campaign_status',
        'recipient_status',
    ];

    public function __construct(private ExperimentMetricsService $metrics) {}

    /** @return list<string> */
    public function headers(): array
    {
        return self::HEADERS;
    }

    /** @return list<string> */
    public function evidenceHeaders(): array
    {
        return self::HEADERS;
    }

    /**
     * @param  array<string, scalar|list<string>|null>  $filters
     * @return iterable<int, list<int|string|null>>
     */
    public function rows(MailExperiment $experiment, array $filters = []): iterable
    {
        foreach ($this->metrics->report($experiment, $filters)['evidence'] as $evidence) {
            yield array_map(fn (string $header): int|string|null => $this->scalar($evidence[$header] ?? null), self::HEADERS);
        }
    }

    /** @param array<string, scalar|list<string>|null> $filters */
    public function toCsv(MailExperiment $experiment, array $filters = []): string
    {
        $csv = Writer::createFromString('');
        $csv->addFormatter(new EscapeFormula);
        $csv->insertOne(self::HEADERS);

        foreach ($this->rows($experiment, $filters) as $row) {
            $csv->insertOne($row);
        }

        return $csv->toString();
    }

    /**
     * @param  array<string, scalar|list<string>|null>  $filters
     * @return array<string, mixed>
     */
    public function toArray(MailExperiment $experiment, array $filters = []): array
    {
        return $this->metrics->report($experiment, $filters);
    }

    /** @param array<string, scalar|list<string>|null> $filters */
    public function toJson(MailExperiment $experiment, array $filters = []): string
    {
        return json_encode(
            $this->toArray($experiment, $filters),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /** @param array<string, scalar|list<string>|null> $filters */
    public function downloadCsv(MailExperiment $experiment, array $filters = []): StreamedResponse
    {
        return CsvExport::stream(
            'mail-experiment-'.$experiment->id.'.csv',
            self::HEADERS,
            $this->rows($experiment, $filters),
        );
    }

    /** @param array<string, scalar|list<string>|null> $filters */
    public function downloadJson(MailExperiment $experiment, array $filters = []): JsonResponse
    {
        return response()->json($this->toArray($experiment, $filters));
    }

    private function scalar(mixed $value): int|string|null
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (string) $value;
        }

        return $value === null
            ? null
            : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
