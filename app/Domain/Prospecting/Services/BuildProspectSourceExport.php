<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\Prospecting\Exceptions\SourcePolicyViolation;
use App\Domain\Prospecting\Support\SourcePolicyEnforcer;
use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use App\Models\SourceObservation;
use BackedEnum;
use DateTimeInterface;

/** Build one policy-aware, rectangular CSV containing the complete safe source lineage. */
final class BuildProspectSourceExport
{
    public function __construct(private readonly SourcePolicyEnforcer $policy) {}

    /** @return list<string> */
    public function headers(): array
    {
        return [
            'record_type', 'source_id', 'source_name', 'source_kind', 'source_status',
            'version_id', 'version', 'schema_version', 'origin', 'policy_hash',
            'policy', 'configuration_hash', 'run_id', 'run_state', 'received',
            'valid', 'accepted', 'created', 'linked', 'duplicate', 'conflicted',
            'suppressed', 'rejected', 'expired', 'observation_id', 'row_number',
            'entity_type', 'disposition', 'match_method', 'account_id', 'person_id',
            'observed_values', 'omission_reason', 'membership_id', 'membership_state',
            'first_observed_at', 'last_observed_at', 'created_at', 'finished_at',
        ];
    }

    public function assertExportable(ProspectSource $source): void
    {
        $source->loadMissing('currentVersion');
        $this->policy->assertExportable($source->currentVersion()->firstOrFail());
    }

    /** @return iterable<int, list<int|string|null>> */
    public function rows(ProspectSource $source): iterable
    {
        $this->assertExportable($source);
        $currentVersion = $source->currentVersion()->firstOrFail();
        $membershipOmissionReason = $this->exportOmissionReason($currentVersion);

        yield $this->row($source, 'source');

        foreach ($source->versions()->orderBy('version')->cursor() as $version) {
            yield $this->row($source, 'version', [
                'version_id' => (string) $version->id,
                'version' => (int) $version->version,
                'schema_version' => (int) $version->schema_version,
                'origin' => $this->scalar($version->origin),
                'policy_hash' => (string) $version->policy_hash,
                'policy' => $this->json((array) $version->policy_snapshot),
                'configuration_hash' => (string) $version->configuration_hash,
                'created_at' => $this->date($version->created_at),
            ]);
        }

        foreach ($source->runs()->orderBy('created_at')->cursor() as $run) {
            yield $this->row($source, 'run', [
                'version_id' => (string) $run->prospect_source_version_id,
                'run_id' => (string) $run->id,
                'run_state' => $this->scalar($run->state),
                'received' => (int) $run->received,
                'valid' => (int) $run->valid,
                'accepted' => (int) $run->accepted,
                'created' => (int) $run->created,
                'linked' => (int) $run->linked,
                'duplicate' => (int) $run->duplicate,
                'conflicted' => (int) $run->conflicted,
                'suppressed' => (int) $run->suppressed,
                'rejected' => (int) $run->rejected,
                'expired' => (int) $run->expired,
                'created_at' => $this->date($run->created_at),
                'finished_at' => $this->date($run->finished_at),
            ]);
        }

        $observations = $source->observations()
            ->with('version')
            ->orderBy('retrieved_at')
            ->orderBy('id')
            ->cursor();
        foreach ($observations as $observation) {
            [$values, $omissionReason] = $this->observationValues($observation);
            yield $this->row($source, 'observation', [
                'version_id' => (string) $observation->prospect_source_version_id,
                'run_id' => (string) $observation->source_run_id,
                'observation_id' => (string) $observation->id,
                'row_number' => $observation->row_number,
                'entity_type' => $this->scalar($observation->entity_type),
                'disposition' => $this->scalar($observation->disposition),
                'match_method' => $observation->match_method,
                'account_id' => $omissionReason === null ? $observation->account_id : null,
                'person_id' => $omissionReason === null ? $observation->person_id : null,
                'observed_values' => $values === [] ? null : $this->json($values),
                'omission_reason' => $omissionReason,
                'created_at' => $this->date($observation->retrieved_at),
            ]);
        }

        foreach ($source->accountMemberships()->orderBy('id')->cursor() as $membership) {
            yield $this->row($source, 'account_membership', [
                'account_id' => $membershipOmissionReason === null ? (int) $membership->account_id : null,
                'omission_reason' => $membershipOmissionReason,
                'membership_id' => (string) $membership->public_id,
                'membership_state' => $this->scalar($membership->state),
                'first_observed_at' => $this->date($membership->first_observed_at),
                'last_observed_at' => $this->date($membership->last_observed_at),
            ]);
        }
        foreach ($source->personMemberships()->orderBy('id')->cursor() as $membership) {
            yield $this->row($source, 'person_membership', [
                'person_id' => $membershipOmissionReason === null ? (int) $membership->person_id : null,
                'omission_reason' => $membershipOmissionReason,
                'membership_id' => (string) $membership->public_id,
                'membership_state' => $this->scalar($membership->state),
                'first_observed_at' => $this->date($membership->first_observed_at),
                'last_observed_at' => $this->date($membership->last_observed_at),
            ]);
        }
    }

    /** @return array{array<string, mixed>, string|null} */
    private function observationValues(SourceObservation $observation): array
    {
        if ($observation->redacted_at !== null || $observation->expired_at !== null) {
            return [[], 'retention_expired'];
        }
        if (in_array((string) $observation->export_classification, ['prohibited', 'restricted'], true)) {
            return [[], 'restricted_by_observation_policy'];
        }

        try {
            $version = $observation->version()->firstOrFail();
            if ($this->exportOmissionReason($version) !== null) {
                return [[], 'restricted_by_observation_policy'];
            }
            $stored = $observation->getAttribute('observed_values');
            $exportableValues = [];
            if (is_array($stored)) {
                foreach ($stored as $field => $value) {
                    if (is_string($field)) {
                        $exportableValues[$field] = $value;
                    }
                }
            }
            $values = $this->policy->filterFields(
                $exportableValues,
                $version,
                'export',
            );

            return [$values, null];
        } catch (SourcePolicyViolation) {
            return [[], 'restricted_by_source_policy'];
        }
    }

    private function exportOmissionReason(ProspectSourceVersion $version): ?string
    {
        $classification = (string) ($version->policy_snapshot['export_classification'] ?? 'standard');

        return in_array($classification, ['prohibited', 'restricted'], true)
            ? 'restricted_by_source_policy'
            : null;
    }

    /**
     * @param  array<string, int|string|null>  $values
     * @return list<int|string|null>
     */
    private function row(ProspectSource $source, string $recordType, array $values = []): array
    {
        $defaults = array_fill_keys($this->headers(), null);
        $row = array_replace($defaults, [
            'record_type' => $recordType,
            'source_id' => (string) $source->id,
            'source_name' => (string) $source->display_name,
            'source_kind' => $this->scalar($source->kind),
            'source_status' => $this->scalar($source->status),
        ], $values);

        return array_values($row);
    }

    private function scalar(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }
}
