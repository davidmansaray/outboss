<?php

namespace App\Domain\Mail\Services;

use App\Domain\Campaigns\DTOs\EnrollmentCandidate;
use App\Domain\Mail\DTOs\AssignmentDecision;
use App\Domain\Mail\Enums\RecipientMailboxProvider;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\MailExperimentCohort;
use App\Models\Person;
use Illuminate\Support\Collection;

class DeterministicStratifiedAssignment
{
    public const EXCLUSION_TARGET_CAP_REACHED = 'target_cap_reached';

    /**
     * Allocate candidates into cohorts using a keyed hash of the frozen seed
     * and normalised email. Every ordering decision is made after canonical
     * sorting, so database iteration order cannot affect the result.
     *
     * @param  iterable<int, mixed>  $candidates
     * @param  array<int|string, mixed>  $cohorts
     * @return list<AssignmentDecision>
     */
    public function assign(
        string $seed,
        iterable $candidates,
        array $cohorts = ['a', 'b'],
        ?int $targetAssignmentsPerCohort = null,
    ): array {
        $cohortKeys = $this->cohortKeys($cohorts);

        if ($cohortKeys === []) {
            return [];
        }

        if ($targetAssignmentsPerCohort !== null && $targetAssignmentsPerCohort < 1) {
            throw new \InvalidArgumentException('The target assignments per cohort must be at least one.');
        }

        $normalised = [];
        foreach ($candidates as $candidate) {
            $normalised[] = $this->normaliseCandidate($candidate);
        }

        $decisions = [];
        $uniqueCandidates = [];
        $byEmail = [];
        foreach ($normalised as $candidate) {
            $email = $candidate['email_normalized'];
            $byEmail[$email][] = $candidate;
        }

        // One email can never enter two cohorts. Pick a canonical row for
        // duplicate input independent of the input iteration order.
        foreach ($byEmail as $email => $duplicates) {
            usort($duplicates, fn (array $left, array $right): int => $this->candidateSort($left, $right));
            $canonical = array_shift($duplicates);

            if (($canonical['exclusion_reason'] ?? null) !== null) {
                $decisions[] = $this->excludedDecision(
                    $canonical,
                    $seed,
                    (string) $canonical['exclusion_reason'],
                    0,
                );

                continue;
            }

            $uniqueCandidates[] = $canonical;
        }

        // A person may have multiple addresses. The canonical email above is
        // still selected by email, then this pass removes a second address for
        // the same person in a stable way.
        $eligible = [];
        $personGroups = [];
        foreach ($uniqueCandidates as $candidate) {
            if (isset($candidate['exclusion_reason'])) {
                continue;
            }

            if ($candidate['person_id'] !== null) {
                $personGroups[(string) $candidate['person_id']][] = $candidate;
            } else {
                $eligible[] = $candidate;
            }
        }

        foreach ($personGroups as $personCandidates) {
            usort($personCandidates, fn (array $left, array $right): int => $this->candidateSort($left, $right));
            $eligible[] = array_shift($personCandidates);

            foreach ($personCandidates as $duplicatePosition => $duplicate) {
                // Keep the duplicate email as auditable exclusion evidence,
                // but clear person_id so the database's unique non-null
                // person constraint remains valid.
                $duplicate['person_id'] = null;
                $decisions[] = $this->excludedDecision(
                    $duplicate,
                    $seed,
                    'duplicate_person',
                    $duplicatePosition,
                );
            }
        }

        $strata = [];
        foreach ($eligible as $candidate) {
            $strata[$candidate['stratum_key']][] = $candidate;
        }

        ksort($strata, SORT_STRING);
        foreach ($strata as $stratum => $members) {
            usort($members, function (array $left, array $right) use ($seed): int {
                $leftKey = $this->assignmentKey($seed, $left['email_normalized']);
                $rightKey = $this->assignmentKey($seed, $right['email_normalized']);

                return $leftKey <=> $rightKey
                    ?: $this->candidateSort($left, $right);
            });
            $strata[$stratum] = $members;
        }

        if ($targetAssignmentsPerCohort !== null) {
            $decisions = [
                ...$decisions,
                ...$this->assignCapped(
                    $seed,
                    $strata,
                    $cohortKeys,
                    $targetAssignmentsPerCohort,
                ),
            ];
        } else {
            // Continue the round-robin across stratum boundaries. Restarting
            // at the first cohort for every odd-sized stratum can turn two
            // individually balanced 2:1 allocations into a 4:2 experiment.
            // The canonical stratum/member ordering above makes this global
            // offset deterministic and keeps total cohort sizes within one.
            $cohortOffset = 0;
            $cohortCount = count($cohortKeys);
            foreach ($strata as $stratum => $members) {
                foreach ($members as $position => $candidate) {
                    $decisions[] = $this->assignedDecision(
                        $seed,
                        $candidate,
                        (string) $stratum,
                        $cohortKeys[($cohortOffset + $position) % $cohortCount],
                    );
                }

                $cohortOffset = ($cohortOffset + count($members)) % $cohortCount;
            }
        }

        $decisions = $this->deduplicatePeople($decisions);

        usort($decisions, fn (AssignmentDecision $left, AssignmentDecision $right): int => $left->assignmentKey <=> $right->assignmentKey
            ?: (($left->exclusionReason ?? '') <=> ($right->exclusionReason ?? '')));

        return $decisions;
    }

    /**
     * A person can arrive with more than one address and both addresses may
     * already be excluded by eligibility. Keep one immutable person reference
     * and persist every additional address as an exclusion with a null person
     * foreign key, preserving the database uniqueness invariant.
     *
     * @param  list<AssignmentDecision>  $decisions
     * @return list<AssignmentDecision>
     */
    private function deduplicatePeople(array $decisions): array
    {
        $seen = [];
        $result = [];

        foreach ($decisions as $decision) {
            if ($decision->personId === null || ! isset($seen[$decision->personId])) {
                if ($decision->personId !== null) {
                    $seen[$decision->personId] = true;
                }
                $result[] = $decision;

                continue;
            }

            $result[] = new AssignmentDecision(
                emailNormalized: $decision->emailNormalized,
                recipientDomain: $decision->recipientDomain,
                recipientProvider: $decision->recipientProvider,
                stratumKey: $decision->stratumKey,
                assignmentKey: hash_hmac('sha256', $decision->assignmentKey.'|duplicate_person', $decision->assignmentKey),
                cohortKey: null,
                exclusionReason: 'duplicate_person',
                personId: null,
                accountId: $decision->accountId,
                emailAddress: $decision->emailAddress,
                fullName: $decision->fullName,
                firstName: $decision->firstName,
                company: $decision->company,
                providerMxSnapshot: $decision->providerMxSnapshot,
            );
        }

        return $result;
    }

    /**
     * Service-style alias with the candidate-first argument order used by
     * most application services.
     *
     * @param  iterable<int, mixed>  $candidates
     * @param  array<int|string, mixed>  $cohorts
     * @return list<AssignmentDecision>
     */
    public function handle(
        iterable $candidates,
        string $seed,
        array $cohorts = ['a', 'b'],
        ?int $targetAssignmentsPerCohort = null,
    ): array {
        return $this->assign($seed, $candidates, $cohorts, $targetAssignmentsPerCohort);
    }

    /**
     * Select at most the approved total sample and assign it in a global
     * deterministic round-robin. The weighted stratum interleave keeps the
     * selected sample representative of the available strata while the final
     * round-robin guarantees exactly the same count in every cohort whenever
     * enough eligible candidates exist.
     *
     * Candidates which do not fit the approved sample are retained as
     * exclusion evidence. They are never silently discarded, so a preparation
     * retry can reproduce both the selected and omitted rows from its input
     * snapshot.
     *
     * @param  array<string, list<array<string, mixed>>>  $strata
     * @param  list<string>  $cohortKeys
     * @return list<AssignmentDecision>
     */
    private function assignCapped(
        string $seed,
        array $strata,
        array $cohortKeys,
        int $targetAssignmentsPerCohort,
    ): array {
        $totalEligible = array_sum(array_map('count', $strata));
        $capacity = $targetAssignmentsPerCohort * count($cohortKeys);
        $selectedLimit = min($totalEligible, $capacity);
        $selected = $this->stratifiedSample($strata, $selectedLimit);
        $selectedEmails = [];
        $decisions = [];

        foreach ($selected as $position => $candidate) {
            $selectedEmails[$candidate['email_normalized']] = true;
            $decisions[] = $this->assignedDecision(
                $seed,
                $candidate,
                $candidate['stratum_key'],
                $cohortKeys[$position % count($cohortKeys)],
            );
        }

        $omitted = [];
        foreach ($strata as $members) {
            foreach ($members as $candidate) {
                if (! isset($selectedEmails[$candidate['email_normalized']])) {
                    $omitted[] = $candidate;
                }
            }
        }

        usort($omitted, fn (array $left, array $right): int => $this->candidateSort($left, $right));
        foreach ($omitted as $ordinal => $candidate) {
            $decisions[] = $this->excludedDecision(
                $candidate,
                $seed,
                self::EXCLUSION_TARGET_CAP_REACHED,
                $ordinal,
            );
        }

        return $decisions;
    }

    /**
     * Interleave sorted strata by largest deterministic proportional deficit.
     * Integer arithmetic avoids floating point tie drift across runtimes.
     *
     * @param  array<string, list<array<string, mixed>>>  $strata
     * @return list<array<string, mixed>>
     */
    private function stratifiedSample(array $strata, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $total = array_sum(array_map('count', $strata));
        if ($total === 0) {
            return [];
        }

        $selectedCounts = array_fill_keys(array_keys($strata), 0);
        $selected = [];

        for ($position = 0; $position < $limit; $position++) {
            $bestStratum = null;
            $bestDeficit = null;

            foreach ($strata as $stratum => $members) {
                $selectedCount = $selectedCounts[$stratum];
                if ($selectedCount >= count($members)) {
                    continue;
                }

                $deficit = (($position + 1) * count($members)) - ($selectedCount * $total);
                if ($bestDeficit === null
                    || $deficit > $bestDeficit
                    || ($deficit === $bestDeficit && (string) $stratum < (string) $bestStratum)) {
                    $bestStratum = (string) $stratum;
                    $bestDeficit = $deficit;
                }
            }

            if ($bestStratum === null) {
                break;
            }

            $memberPosition = $selectedCounts[$bestStratum];
            $selected[] = $strata[$bestStratum][$memberPosition];
            $selectedCounts[$bestStratum]++;
        }

        return $selected;
    }

    /** @param array<string, mixed> $candidate */
    private function assignedDecision(
        string $seed,
        array $candidate,
        string $stratum,
        string $cohortKey,
    ): AssignmentDecision {
        return new AssignmentDecision(
            emailNormalized: $candidate['email_normalized'],
            recipientDomain: $candidate['recipient_domain'],
            recipientProvider: $candidate['recipient_provider'],
            stratumKey: $stratum,
            assignmentKey: $this->assignmentKey($seed, $candidate['email_normalized']),
            cohortKey: $cohortKey,
            personId: $candidate['person_id'],
            accountId: $candidate['account_id'],
            emailAddress: $candidate['email_address'],
            fullName: $candidate['full_name'],
            firstName: $candidate['first_name'],
            company: $candidate['company'],
            providerMxSnapshot: $candidate['provider_mx_snapshot'],
        );
    }

    /**
     * @param  array<int|string, mixed>  $cohorts
     * @return list<string>
     */
    private function cohortKeys(array $cohorts): array
    {
        $keys = [];

        foreach ($cohorts as $key => $cohort) {
            $value = $cohort instanceof MailExperimentCohort
                ? $cohort->getAttribute('key')
                : (is_array($cohort) ? ($cohort['key'] ?? $key) : $cohort);

            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            $keys[] = (string) $value;
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * @return array{
     *   email_normalized: string,
     *   recipient_domain: string,
     *   recipient_provider: RecipientMailboxProvider,
     *   stratum_key: string,
     *   person_id: int|null,
     *   account_id: int|null,
     *   email_address: string,
     *   full_name: string|null,
     *   first_name: string|null,
     *   company: string|null,
     *   provider_mx_snapshot: list<string>,
     *   exclusion_reason: string|null
     * }
     */
    private function normaliseCandidate(mixed $candidate): array
    {
        if ($candidate instanceof Person) {
            $candidate = [
                'person_id' => $candidate->getKey(),
                'account_id' => $candidate->getAttribute('account_id'),
                'email_address' => $candidate->getAttribute('email_address'),
                'email_normalized' => $candidate->getAttribute('email_normalized'),
                'full_name' => $candidate->getAttribute('full_name'),
                'first_name' => $candidate->getAttribute('first_name'),
                'company' => $candidate->account?->getAttribute('name'),
                'workspace_id' => $candidate->getAttribute('workspace_id'),
            ];
        } elseif ($candidate instanceof EnrollmentCandidate) {
            $candidate = [
                'person_id' => $candidate->personId,
                'account_id' => $candidate->accountId,
                'email_address' => $candidate->emailAddress,
                'email_normalized' => $candidate->emailNormalized,
                'full_name' => $candidate->fullName,
                'first_name' => $candidate->firstName,
                'company' => $candidate->company,
            ];
        } elseif ($candidate instanceof Collection) {
            $candidate = $candidate->all();
        }

        if (is_object($candidate)) {
            $candidate = method_exists($candidate, 'toArray')
                ? $candidate->toArray()
                : get_object_vars($candidate);
        }

        $candidate = is_array($candidate) ? $candidate : [];
        $emailAddress = (string) ($candidate['email_address'] ?? $candidate['email'] ?? '');
        $emailNormalized = EmailNormalizer::normalize(
            (string) ($candidate['email_normalized'] ?? $emailAddress),
        );
        $domain = EmailNormalizer::domain($emailNormalized) ?? '';
        $provider = $candidate['recipient_provider'] ?? $candidate['provider'] ?? RecipientMailboxProvider::Unknown;
        if (is_string($provider)) {
            $provider = RecipientMailboxProvider::tryFrom($provider) ?? RecipientMailboxProvider::Unknown;
        }
        if (! $provider instanceof RecipientMailboxProvider) {
            $provider = RecipientMailboxProvider::Unknown;
        }

        $snapshot = $candidate['provider_mx_snapshot'] ?? $candidate['mx'] ?? [];
        $snapshot = is_array($snapshot)
            ? array_values(array_filter($snapshot, 'is_string'))
            : [];
        $stratum = trim((string) ($candidate['stratum_key'] ?? $provider->value));

        $reason = $candidate['exclusion_reason'] ?? null;
        if ($reason === null && ! EmailNormalizer::isValid($emailAddress)) {
            $reason = 'invalid_email';
        }

        if ($reason === null && array_key_exists('eligible', $candidate) && $candidate['eligible'] === false) {
            $reason = 'ineligible';
        }

        return [
            'email_normalized' => $emailNormalized,
            'recipient_domain' => $domain,
            'recipient_provider' => $provider,
            'stratum_key' => $stratum !== '' ? $stratum : $provider->value,
            'person_id' => is_numeric($candidate['person_id'] ?? null) ? (int) $candidate['person_id'] : null,
            'account_id' => is_numeric($candidate['account_id'] ?? null) ? (int) $candidate['account_id'] : null,
            'email_address' => $emailAddress,
            'full_name' => is_string($candidate['full_name'] ?? null) ? $candidate['full_name'] : null,
            'first_name' => is_string($candidate['first_name'] ?? null) ? $candidate['first_name'] : null,
            'company' => is_string($candidate['company'] ?? null) ? $candidate['company'] : null,
            'provider_mx_snapshot' => $snapshot,
            'exclusion_reason' => $reason === null ? null : (string) $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function candidateSort(array $left, array $right): int
    {
        return $this->candidateSortKey($left) <=> $this->candidateSortKey($right);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function candidateSortKey(array $candidate): array
    {
        $provider = $candidate['recipient_provider'] ?? '';
        $provider = $provider instanceof RecipientMailboxProvider ? $provider->value : (string) $provider;
        $mx = $candidate['provider_mx_snapshot'] ?? [];
        $mx = is_array($mx) ? implode(',', array_map('strval', $mx)) : (string) $mx;

        return [
            (string) ($candidate['email_normalized'] ?? ''),
            (string) ($candidate['person_id'] ?? ''),
            (string) ($candidate['account_id'] ?? ''),
            (string) ($candidate['email_address'] ?? ''),
            $provider,
            (string) ($candidate['stratum_key'] ?? ''),
            $mx,
            (string) ($candidate['exclusion_reason'] ?? ''),
            (string) ($candidate['full_name'] ?? ''),
            (string) ($candidate['first_name'] ?? ''),
            (string) ($candidate['company'] ?? ''),
        ];
    }

    private function assignmentKey(string $seed, string $email): string
    {
        return hash_hmac('sha256', $email, $seed);
    }

    /** @param array<string, mixed> $candidate */
    private function excludedDecision(array $candidate, string $seed, string $reason, int $ordinal): AssignmentDecision
    {
        return new AssignmentDecision(
            emailNormalized: $candidate['email_normalized'],
            recipientDomain: $candidate['recipient_domain'],
            recipientProvider: $candidate['recipient_provider'],
            stratumKey: $candidate['stratum_key'],
            assignmentKey: hash_hmac(
                'sha256',
                $candidate['email_normalized'].'|excluded|'.$reason.'|'.$ordinal,
                $seed,
            ),
            cohortKey: null,
            exclusionReason: $reason,
            personId: $candidate['person_id'],
            accountId: $candidate['account_id'],
            emailAddress: $candidate['email_address'],
            fullName: $candidate['full_name'],
            firstName: $candidate['first_name'],
            company: $candidate['company'],
            providerMxSnapshot: $candidate['provider_mx_snapshot'],
        );
    }
}
