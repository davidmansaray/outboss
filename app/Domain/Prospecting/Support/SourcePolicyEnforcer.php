<?php

namespace App\Domain\Prospecting\Support;

use App\Domain\Prospecting\DTOs\SourcePolicySnapshot;
use App\Domain\Prospecting\Exceptions\SourcePolicyViolation;
use App\Models\ProspectSourceVersion;
use DateTimeInterface;
use InvalidArgumentException;

final class SourcePolicyEnforcer
{
    /** @param array<string, mixed>|ProspectSourceVersion|SourcePolicySnapshot $source
     * @return array{allowed: bool, result: string, policy_hash: string}
     */
    public function contactEligibility(
        ProspectSourceVersion|SourcePolicySnapshot|array $source,
        string $purpose = 'prospecting',
    ): array {
        $policy = $this->policy($source);
        $snapshot = SourcePolicySnapshot::from($policy);
        $allowedPurposes = array_values(array_filter((array) ($policy['allowed_purposes'] ?? []), 'is_string'));
        $eligibility = (string) ($policy['contact_eligibility'] ?? 'eligible');
        $result = match (true) {
            $snapshot->isExpired() => 'policy_expired',
            ! in_array($purpose, $allowedPurposes, true) => 'purpose_not_allowed',
            $eligibility === 'ineligible' => 'contact_ineligible',
            $eligibility === 'restricted' => 'contact_restricted',
            default => 'allowed',
        };

        return [
            'allowed' => $result === 'allowed',
            'result' => $result,
            'policy_hash' => $source instanceof ProspectSourceVersion
                ? (string) $source->policy_hash
                : SourcePolicySnapshot::hashPolicy($policy),
        ];
    }

    /** @param array<string, mixed>|ProspectSourceVersion|SourcePolicySnapshot $source */
    public function assertRunnable(ProspectSourceVersion|SourcePolicySnapshot|array $source): void
    {
        $policy = $source instanceof ProspectSourceVersion
            ? (array) $source->policy_snapshot
            : ($source instanceof SourcePolicySnapshot ? $source->policy : $source);
        $snapshot = SourcePolicySnapshot::from($policy);
        if ($snapshot->isExpired()) {
            throw new SourcePolicyViolation('The source policy is expired.', 'policy_expired');
        }
        if (($policy['contact_eligibility'] ?? 'eligible') === 'ineligible') {
            throw new SourcePolicyViolation('This source is not approved for contact eligibility.', 'contact_ineligible');
        }
    }

    /** @param array<string, mixed>|ProspectSourceVersion|SourcePolicySnapshot $source */
    public function assertExportable(ProspectSourceVersion|SourcePolicySnapshot|array $source): void
    {
        $policy = $this->policy($source);
        $snapshot = SourcePolicySnapshot::from($policy);
        if (! $snapshot->allowsExport() || ($policy['export_classification'] ?? 'standard') === 'prohibited') {
            throw new SourcePolicyViolation('Export is not allowed by this source policy.', 'export_forbidden');
        }
        if ($snapshot->isExpired()) {
            throw new SourcePolicyViolation('The source policy is expired.', 'policy_expired');
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>|ProspectSourceVersion|SourcePolicySnapshot  $source
     * @return array<string, mixed>
     */
    public function filterFields(array $values, ProspectSourceVersion|SourcePolicySnapshot|array $source, string $mode = 'display'): array
    {
        if (! in_array($mode, ['display', 'export'], true)) {
            throw new InvalidArgumentException('Source policy field mode is invalid.');
        }
        $policy = $this->policy($source);
        if ($mode === 'export') {
            $this->assertExportable($source);
        } elseif (SourcePolicySnapshot::from($policy)->isExpired()
            || in_array(($policy['display_classification'] ?? 'standard'), ['restricted', 'prohibited'], true)) {
            return [];
        }
        $allowed = $policy[$mode === 'export' ? 'export_fields' : 'display_fields'] ?? null;
        if (! is_array($allowed) || $allowed === []) {
            return $values;
        }
        if (! array_is_list($allowed)) {
            return [];
        }

        return array_intersect_key($values, array_flip(array_filter($allowed, 'is_string')));
    }

    /** @param array<string, mixed>|ProspectSourceVersion|SourcePolicySnapshot $source */
    public function allowsDisplay(ProspectSourceVersion|SourcePolicySnapshot|array $source): bool
    {
        $policy = $this->policy($source);

        return ! SourcePolicySnapshot::from($policy)->isExpired()
            && ! in_array(($policy['display_classification'] ?? 'standard'), ['restricted', 'prohibited'], true);
    }

    /** @param array<string, mixed>|SourcePolicySnapshot $source */
    public function retentionDate(SourcePolicySnapshot|array $source, ?DateTimeInterface $observedAt = null): ?DateTimeInterface
    {
        $policy = $source instanceof SourcePolicySnapshot ? $source->policy : $source;
        $days = $policy['normalized_retention_days'] ?? null;
        if (! is_int($days) && ! (is_string($days) && preg_match('/^[0-9]+$/', $days) === 1)) {
            return null;
        }
        $date = new \DateTimeImmutable(($observedAt ?? now())->format(DateTimeInterface::ATOM));

        return $date->modify('+'.((int) $days).' days');
    }

    /**
     * @param  array<string, mixed>|ProspectSourceVersion|SourcePolicySnapshot  $source
     * @return array<string, mixed>
     */
    private function policy(ProspectSourceVersion|SourcePolicySnapshot|array $source): array
    {
        return $source instanceof ProspectSourceVersion
            ? (array) $source->policy_snapshot
            : ($source instanceof SourcePolicySnapshot ? $source->policy : $source);
    }
}
