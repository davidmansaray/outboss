<?php

namespace App\Domain\Mail\Services;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\MailExperiment;
use App\Models\MailExperimentAssignment;
use App\Models\SequenceVersion;

class CampaignSnapshotService
{
    /**
     * Capture only inputs that can affect an experiment. IDs, names, status,
     * lifecycle timestamps, and cohort-specific sender/domain identities are
     * deliberately kept separate from the equivalence payload.
     *
     * @param  array<string, mixed>  $eligibility
     * @return array<string, mixed>
     */
    public function snapshot(
        Campaign $campaign,
        array $eligibility = [],
        ?MailExperiment $experiment = null,
    ): array {
        $campaign->loadMissing([
            'draftVersion.activeSteps',
            'publishedVersion.activeSteps',
            'mailboxes',
        ]);

        $version = $campaign->publishedVersion ?? $campaign->draftVersion;
        $steps = [];
        if ($version instanceof SequenceVersion) {
            foreach ($version->activeSteps()->get() as $step) {
                $steps[] = [
                    'position' => (int) $step->getAttribute('position'),
                    'title' => (string) $step->getAttribute('title'),
                    'delay_minutes' => (int) $step->getAttribute('delay_minutes'),
                    'send_as' => $this->enumValue($step->getAttribute('send_as')),
                    'subject_template' => $step->getAttribute('subject_template'),
                    'body_template' => (string) $step->getAttribute('body_template'),
                    'subject_spintax_enabled' => (bool) $step->getAttribute('subject_spintax_enabled'),
                    'body_spintax_enabled' => (bool) $step->getAttribute('body_spintax_enabled'),
                ];
            }
        }

        $mailboxes = $campaign->mailboxes()->orderByPivot('position')->get();
        $dailySendLimit = $campaign->getAttribute('daily_send_limit');

        return $this->canonical([
            'sequence' => [
                'steps' => $steps,
            ],
            'schedule' => [
                'send_mode' => $this->enumValue($campaign->getAttribute('send_mode')),
                'timezone' => (string) $campaign->getAttribute('timezone'),
                'send_window_start' => $this->timeValue($campaign->getAttribute('send_window_start')),
                'send_window_end' => $this->timeValue($campaign->getAttribute('send_window_end')),
                'business_days_only' => (bool) $campaign->getAttribute('business_days_only'),
                ...($dailySendLimit === null ? [] : ['daily_send_limit' => (int) $dailySendLimit]),
            ],
            'tracking' => [
                'open_tracking_enabled' => (bool) $campaign->getAttribute('open_tracking_enabled'),
            ],
            'safety' => [
                'mailbox_caps' => $mailboxes->map(fn (Mailbox $mailbox): array => [
                    'position' => (int) ($mailbox->getAttribute('pivot')->position ?? 0),
                    'daily_send_cap' => $mailbox->getAttribute('daily_send_cap') === null
                        ? null
                        : (int) $mailbox->getAttribute('daily_send_cap'),
                    'domain_hourly_limit' => (int) config('outboss.sending.domain_hourly_limit', 6),
                ])->values()->all(),
            ],
            'sender_signatures' => $mailboxes->map(fn (Mailbox $mailbox): array => [
                'position' => (int) ($mailbox->getAttribute('pivot')->position ?? 0),
                'email' => strtolower(trim((string) $mailbox->getAttribute('email_address'))),
                'display_name' => $mailbox->getAttribute('display_name'),
                'selected_identity' => $mailbox->getAttribute('selected_sender_identity_key'),
                'identities' => $this->canonical($mailbox->getAttribute('sender_identities') ?? []),
            ])->values()->all(),
            'eligibility' => $this->canonicalEligibility($eligibility),
            'approval_evidence' => $this->approvalEvidence($experiment),
            'assignment_snapshot' => $this->assignmentSnapshot($experiment),
            'audience' => [
                'campaign_recipients' => $this->campaignRecipients($campaign),
                'experiment_assignments' => $this->experimentAssignments($experiment),
            ],
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    public function hash(array $snapshot): string
    {
        return hash('sha256', $this->json($snapshot));
    }

    /**
     * Hash a snapshot in the shape used before variation opt-in flags existed.
     * This is accepted only while every flag is false, so a direct database
     * change cannot hide newly enabled rendering from frozen-input checks.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function legacyHashIfVariationsDisabled(array $snapshot): ?string
    {
        $compatible = true;
        $stripFlags = function (mixed $value) use (&$stripFlags, &$compatible): mixed {
            if (! is_array($value)) {
                return $value;
            }

            foreach ($value as $key => $item) {
                if (in_array($key, ['subject_spintax_enabled', 'body_spintax_enabled'], true)) {
                    if ((bool) $item) {
                        $compatible = false;
                    }

                    unset($value[$key]);

                    continue;
                }

                $value[$key] = $stripFlags($item);
            }

            return $value;
        };

        $legacy = $stripFlags($snapshot);

        return $compatible && is_array($legacy) ? $this->hash($legacy) : null;
    }

    /** @param array<string, mixed> $eligibility */
    public function hashCampaign(
        Campaign $campaign,
        array $eligibility = [],
        ?MailExperiment $experiment = null,
    ): string {
        return $this->hash($this->snapshot($campaign, $eligibility, $experiment));
    }

    /**
     * Compare campaign inputs while allowing the sender/domain identity to
     * differ between cohorts. The return value includes all differences for
     * an audit-friendly freeze error.
     *
     * @param  array<string, mixed>  $eligibility
     * @return array{equivalent: bool, differences: list<string>, approved_differences: list<string>}
     */
    public function compare(
        Campaign $left,
        Campaign $right,
        array $eligibility = [],
        ?MailExperiment $experiment = null,
    ): array {
        $leftSnapshot = $this->snapshot($left, $eligibility, $experiment);
        $rightSnapshot = $this->snapshot($right, $eligibility, $experiment);
        $approved = $this->approvedPaths();
        $differenceSet = $this->diff($leftSnapshot, $rightSnapshot, '', $approved);

        return [
            'equivalent' => $differenceSet['differences'] === [],
            'differences' => $differenceSet['differences'],
            'approved_differences' => $differenceSet['approved_differences'],
        ];
    }

    /** @param array<string, mixed> $eligibility */
    public function equivalent(
        Campaign $left,
        Campaign $right,
        array $eligibility = [],
        ?MailExperiment $experiment = null,
    ): bool {
        return $this->compare($left, $right, $eligibility, $experiment)['equivalent'];
    }

    /**
     * @return list<string>
     */
    public function approvedDifferencePaths(): array
    {
        return $this->approvedPaths();
    }

    /** @return list<string> */
    private function approvedPaths(): array
    {
        return [
            'sender_signatures',
            // Each campaign intentionally contains different assigned people.
            // The audience remains part of each cohort's own hash, but those
            // expected differences must not make the two campaign inputs
            // inequivalent at freeze time.
            'audience',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function campaignRecipients(Campaign $campaign): array
    {
        return array_values($campaign->recipients()
            ->orderBy('id')
            ->get()
            ->map(fn (CampaignRecipient $recipient): array => [
                'id' => (int) $recipient->getKey(),
                'person_id' => $this->nullableInt($recipient->getAttribute('person_id')),
                'account_id' => $this->nullableInt($recipient->getAttribute('account_id')),
                'mailbox_id' => $this->nullableInt($recipient->getAttribute('mailbox_id')),
                'sequence_version_id' => $this->nullableInt($recipient->getAttribute('sequence_version_id')),
                'email_address' => strtolower(trim((string) $recipient->getAttribute('email_address'))),
                'email_normalized' => strtolower(trim((string) $recipient->getAttribute('email_normalized'))),
                'removed_at' => $this->dateValue($recipient->getAttribute('removed_at')),
            ])
            ->values()
            ->all());
    }

    /** @return list<array<string, mixed>> */
    private function experimentAssignments(?MailExperiment $experiment): array
    {
        if (! $experiment instanceof MailExperiment) {
            return [];
        }

        return array_values($experiment->assignments()
            ->orderBy('id')
            ->get()
            ->map(fn (MailExperimentAssignment $assignment): array => [
                'id' => (int) $assignment->getKey(),
                'cohort_id' => $this->nullableInt($assignment->getAttribute('mail_experiment_cohort_id')),
                'person_id' => $this->nullableInt($assignment->getAttribute('person_id')),
                'account_id' => $this->nullableInt($assignment->getAttribute('account_id')),
                'campaign_id' => $this->nullableInt($assignment->getAttribute('campaign_id')),
                'campaign_recipient_id' => $this->nullableInt($assignment->getAttribute('campaign_recipient_id')),
                'email_normalized' => strtolower(trim((string) $assignment->getAttribute('email_normalized'))),
                'recipient_domain' => strtolower(trim((string) $assignment->getAttribute('recipient_domain'))),
                'recipient_provider' => $this->backedEnumValue($assignment->getAttribute('recipient_provider')),
                'stratum_key' => (string) $assignment->getAttribute('stratum_key'),
                'assignment_key' => (string) $assignment->getAttribute('assignment_key'),
                'provider_mx_snapshot' => $this->canonical($assignment->getAttribute('provider_mx_snapshot') ?? []),
                'assignment_snapshot_hash' => $assignment->getAttribute('assignment_snapshot_hash'),
                'exclusion_reason' => $assignment->getAttribute('exclusion_reason'),
            ])
            ->values()
            ->all());
    }

    /** @return array<string, mixed> */
    private function approvalEvidence(?MailExperiment $experiment): array
    {
        if (! $experiment instanceof MailExperiment) {
            return [];
        }

        $configuration = $experiment->getAttribute('configuration');
        $evidence = is_array($configuration) ? ($configuration['approval_evidence'] ?? null) : null;

        if (! is_array($evidence)) {
            return [];
        }

        // Confirmation timestamps are audit metadata and are intentionally
        // excluded from replay/freeze hashes.  The approval decision and its
        // stable provenance fields remain bound in the canonical snapshot.
        unset($evidence['confirmed_at']);

        return $this->canonical($evidence);
    }

    /** @return array<string, mixed> */
    private function assignmentSnapshot(?MailExperiment $experiment): array
    {
        if (! $experiment instanceof MailExperiment) {
            return [];
        }

        $snapshot = $experiment->getAttribute('assignment_snapshot');

        return is_array($snapshot) ? $this->canonical($snapshot) : [];
    }

    /**
     * @param  array<string, mixed>  $eligibility
     * @return array<string, mixed>
     */
    private function canonicalEligibility(array $eligibility): array
    {
        /** @var array<string, mixed> $canonical */
        $canonical = $this->canonical($eligibility);

        foreach ([
            'blocked_local_parts',
            'required_fields',
            'allowed_countries',
            'approved_countries',
            'countries',
            'allowed_relationships',
            'approved_relationships',
            'relationships',
            'controlled_seed_allowlist',
            'seed_allowlist',
        ] as $key) {
            if (! is_array($canonical[$key] ?? null) || ! array_is_list($canonical[$key])) {
                continue;
            }

            $values = $canonical[$key];
            usort($values, static fn (mixed $left, mixed $right): int => (string) $left <=> (string) $right);
            $canonical[$key] = $values;
        }

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @param  list<string>  $approved
     * @return array{differences: list<string>, approved_differences: list<string>}
     */
    private function diff(
        array $left,
        array $right,
        string $path,
        array $approved,
    ): array {
        $differences = [];
        $approvedDifferences = [];
        $keys = array_values(array_unique([...array_keys($left), ...array_keys($right)]));
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            $next = $path === '' ? (string) $key : $path.'.'.$key;
            $leftExists = array_key_exists($key, $left);
            $rightExists = array_key_exists($key, $right);

            if (! $leftExists || ! $rightExists) {
                $this->recordDifference($next, $approved, $differences, $approvedDifferences);

                continue;
            }

            if (is_array($left[$key]) && is_array($right[$key])) {
                $nested = $this->diff($left[$key], $right[$key], $next, $approved);
                array_push($differences, ...$nested['differences']);
                array_push($approvedDifferences, ...$nested['approved_differences']);

                continue;
            }

            if ($left[$key] !== $right[$key]) {
                $this->recordDifference($next, $approved, $differences, $approvedDifferences);
            }
        }

        return [
            'differences' => $differences,
            'approved_differences' => $approvedDifferences,
        ];
    }

    /**
     * @param  list<string>  $approved
     * @param  list<string>  $differences
     * @param  list<string>  $approvedDifferences
     */
    private function recordDifference(
        string $path,
        array $approved,
        array &$differences,
        array &$approvedDifferences,
    ): void {
        foreach ($approved as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'.')) {
                $approvedDifferences[] = $path;

                return;
            }
        }

        $differences[] = $path;
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }

        return $value;
    }

    /** @param array<string, mixed> $snapshot */
    private function json(array $snapshot): string
    {
        return (string) json_encode($this->canonical($snapshot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        return $value === null ? null : (string) $value;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value === null ? null : (string) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function backedEnumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }
}
