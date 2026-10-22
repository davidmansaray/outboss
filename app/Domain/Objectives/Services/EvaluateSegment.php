<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Intelligence\Enums\QualificationReviewState;
use App\Domain\Intelligence\Enums\ResearchClaimStatus;
use App\Domain\Objectives\DTOs\SegmentEvaluation;
use App\Domain\Objectives\Enums\AudienceMemberResult;
use App\Domain\Objectives\Enums\TruthValue;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\MessageEvidenceContract;
use App\Domain\Objectives\Support\SegmentCriteriaEvaluator;
use App\Domain\Objectives\Support\SegmentCriteriaValidator;
use App\Domain\Prospecting\Enums\MembershipState;
use App\Domain\Prospecting\Enums\ProspectSourceStatus;
use App\Domain\Prospecting\Support\SourcePolicyEnforcer;
use App\Models\Person;
use App\Models\QualificationDecision;
use App\Models\ReasonToContactVersion;
use App\Models\ResearchClaim;
use App\Models\SegmentVersion;
use App\Models\SuppressionEntry;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

final class EvaluateSegment
{
    public function __construct(
        private readonly SegmentCriteriaValidator $validator,
        private readonly SegmentCriteriaEvaluator $evaluator,
        private readonly SourcePolicyEnforcer $sourcePolicy,
        private readonly MessageEvidenceContract $messageEvidence,
    ) {}

    public function handle(Workspace $workspace, SegmentVersion $segmentVersion): SegmentEvaluation
    {
        $segmentVersion = SegmentVersion::query()
            ->whereKey($segmentVersion->id)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();
        $criteria = $this->validator->validate($segmentVersion->criteria);
        $people = Person::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('account_id')
            ->whereHas('sourceMemberships', fn ($query) => $query->whereIn('state', ['active', 'restricted']))
            ->with(['account', 'sourceMemberships.source.currentVersion'])
            ->orderBy('id')
            ->get();
        $suppressed = SuppressionEntry::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('email_normalized', $people->pluck('email_normalized')->filter()->all())
            ->get()
            ->keyBy('email_normalized');

        $accountIds = $people->pluck('account_id')->filter()->unique()->values();
        $decisions = QualificationDecision::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('account_id', $accountIds)
            ->whereIn('review_state', [QualificationReviewState::Accepted->value, QualificationReviewState::Corrected->value])
            ->orderByDesc('created_at')
            ->get()
            ->unique('account_id')
            ->keyBy('account_id');
        $decisionIds = $decisions->pluck('id');
        $reasons = ReasonToContactVersion::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('qualification_decision_id', $decisionIds)
            ->whereNull('successor_id')
            ->orderByDesc('created_at')
            ->get()
            ->unique('account_id')
            ->keyBy('account_id');
        $claimsByAccount = $this->currentClaims($workspace, $accountIds);

        $members = [];
        $rightsManifest = [];
        foreach ($people as $person) {
            $account = $person->account;
            if ($account === null) {
                continue;
            }
            $sourceMemberships = [];
            $allowedSourceIds = [];
            $allowedSourceKinds = [];
            foreach ($person->sourceMemberships as $membership) {
                $source = $membership->source;
                $version = $source->currentVersion;
                if ($version === null) {
                    continue;
                }
                $rights = $this->sourcePolicy->contactEligibility($version, 'prospecting');
                $membershipState = $membership->state;
                $membershipActive = $membershipState === MembershipState::Active
                    && ($membership->expired_at === null || $membership->expired_at->isFuture());
                $sourceActive = $source->status === ProspectSourceStatus::Active
                    && (string) $source->workspace_id === (string) $workspace->id
                    && (string) $source->current_version_id === (string) $version->id;
                $allowed = $membershipActive && $sourceActive && $rights['allowed'];
                $rightsResult = match (true) {
                    $membershipState === MembershipState::Restricted => 'membership_restricted',
                    $membership->expired_at !== null && ! $membership->expired_at->isFuture() => 'membership_expired',
                    $membershipState !== MembershipState::Active => 'membership_not_active',
                    ! $sourceActive => 'source_not_active',
                    default => $rights['result'],
                };
                if ($allowed) {
                    $allowedSourceIds[] = (string) $membership->prospect_source_id;
                    $allowedSourceKinds[] = $source->kind->value;
                }
                $sourceMemberships[] = [
                    'prospect_source_id' => $membership->prospect_source_id,
                    'prospect_source_version_id' => $version->id,
                    'person_source_membership_id' => $membership->id,
                    'source_observation_id' => $membership->last_source_observation_id,
                    'policy_hash' => $rights['policy_hash'],
                    'rights_result' => $rightsResult,
                    'allowed' => $allowed,
                    'kind' => $source->kind->value,
                    'observed_at' => $membership->last_observed_at->toAtomString(),
                    'expires_at' => $membership->expired_at?->toAtomString(),
                ];
                $rightsManifest[$membership->prospect_source_id] = [
                    'prospect_source_id' => $membership->prospect_source_id,
                    'prospect_source_version_id' => $version->id,
                    'policy_hash' => $rights['policy_hash'],
                    'purpose' => 'prospecting',
                    'result' => $rights['result'],
                ];
            }

            $decision = $decisions->get($account->id);
            if (! $decision instanceof QualificationDecision) {
                $decision = null;
            }
            $reason = $reasons->get($account->id);
            if (! $reason instanceof ReasonToContactVersion) {
                $reason = null;
            }
            $supportedClaims = $claimsByAccount[(int) $account->id] ?? [];
            $companyCategory = null;
            $freshUntil = null;
            $evidenceManifest = [];
            foreach ($supportedClaims as $claim) {
                if ($claim['predicate'] === 'company.category') {
                    $companyCategory = $claim['message_value'];
                }
                $claimFreshUntil = Carbon::parse((string) $claim['fresh_until']);
                if ($freshUntil === null || $claimFreshUntil->lessThan($freshUntil)) {
                    $freshUntil = $claimFreshUntil;
                }
                $evidenceManifest[] = $claim;
            }
            $context = [
                'account' => [
                    'domain_present' => filled($account->domain_normalized),
                    'tags' => (array) $account->tags,
                    'source' => $account->source,
                ],
                'person' => [
                    'id' => (string) $person->id,
                    'email_present' => filled($person->email_normalized),
                    'job_title' => $person->job_title,
                    'tags' => (array) $person->tags,
                    'source' => $person->source,
                ],
                'source' => [
                    'id' => array_values(array_unique($allowedSourceIds)),
                    'kind' => array_values(array_unique($allowedSourceKinds)),
                    'purpose_rights' => $allowedSourceIds !== [] ? 'allowed' : ($sourceMemberships[0]['rights_result'] ?? null),
                ],
                'qualification' => [
                    'outcome' => $decision?->outcome?->value,
                    'confidence' => $decision === null ? null : (float) $decision->confidence,
                ],
                'evidence' => [
                    'supported_claim_count' => count($supportedClaims),
                    'fresh_until' => $freshUntil?->toAtomString(),
                ],
                'claim' => ['company' => ['category' => is_scalar($companyCategory) ? $companyCategory : null]],
            ];
            $criteriaResult = $this->evaluator->evaluate($criteria, $context);
            $emailValid = filter_var($person->email_normalized, FILTER_VALIDATE_EMAIL) !== false;
            $suppression = $suppressed->get($person->email_normalized);
            $result = match (true) {
                $suppression !== null => AudienceMemberResult::Suppressed,
                ! $emailValid => AudienceMemberResult::Excluded,
                $allowedSourceIds === [] => AudienceMemberResult::Excluded,
                $criteriaResult['result'] === TruthValue::True => AudienceMemberResult::Included,
                $criteriaResult['result'] === TruthValue::False => AudienceMemberResult::Excluded,
                default => AudienceMemberResult::Uncertain,
            };
            $exclusionReasons = match (true) {
                $suppression !== null => ['currently_suppressed'],
                ! $emailValid => ['email_missing_or_invalid'],
                $allowedSourceIds === [] => ['source_rights_not_allowed'],
                $criteriaResult['result'] === TruthValue::False => ['criteria_not_matched'],
                $criteriaResult['result'] === TruthValue::Unknown => ['criteria_data_missing'],
                default => [],
            };
            $memberFacts = [
                'account_id' => $account->id,
                'person_id' => $person->id,
                'result' => $result->value,
                'qualification_content_hash' => $decision?->content_hash,
                'reason_content_hash' => $reason?->content_hash,
                'source_lineage_hash' => CanonicalObjectivePayload::hash($sourceMemberships),
                'criterion_results' => $criteriaResult['conditions'],
                'missing_fields' => $criteriaResult['missing_fields'],
                'evidence_manifest' => $evidenceManifest,
            ];
            $members[] = [
                ...$memberFacts,
                'member_hash' => CanonicalObjectivePayload::hash($memberFacts),
                'qualification_decision_id' => $decision?->id,
                'reason_to_contact_version_id' => $reason?->id,
                'inclusion_reasons' => $result === AudienceMemberResult::Included ? ['criteria_matched', 'source_rights_allowed', 'email_present'] : [],
                'exclusion_reasons' => $exclusionReasons,
                'suppression_snapshot' => $suppression === null ? ['suppressed' => false] : ['suppressed' => true, 'reason' => $suppression->reason->value],
                'evidence_fresh_until' => $freshUntil?->toAtomString(),
                'evaluated_at' => now()->toAtomString(),
                'source_memberships' => $sourceMemberships,
            ];
        }

        $counts = $this->counts($members);
        $criteriaHash = CanonicalObjectivePayload::hash($criteria);
        $hashableMembers = array_map(static fn (array $member): array => [
            'account_id' => $member['account_id'],
            'person_id' => $member['person_id'],
            'member_hash' => $member['member_hash'],
        ], $members);
        $sourceRights = array_values($rightsManifest);

        return new SegmentEvaluation(
            members: $members,
            counts: $counts,
            sourceRights: $sourceRights,
            criteriaHash: $criteriaHash,
            evaluationHash: CanonicalObjectivePayload::hash([
                'workspace_id' => $workspace->id,
                'segment_version_id' => $segmentVersion->id,
                'criteria_hash' => $criteriaHash,
                'members' => $hashableMembers,
                'source_rights' => $sourceRights,
                'evaluator_version' => 'segment_evaluator_v1',
            ]),
        );
    }

    /** @param iterable<int, int> $accountIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function currentClaims(Workspace $workspace, iterable $accountIds): array
    {
        $claims = ResearchClaim::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('account_id', $accountIds)
            ->whereIn('status', [ResearchClaimStatus::Supported->value, ResearchClaimStatus::OwnerConfirmed->value])
            ->where('safe_for_message', true)
            ->whereNotNull('fresh_until')
            ->where('fresh_until', '>', now())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('evidence')
            ->orderBy('id')
            ->get();

        $grouped = [];
        foreach ($claims as $claim) {
            $manifest = $this->messageEvidence->currentManifest(
                $claim,
                (int) $workspace->id,
                (int) $claim->account_id,
            );
            if ($manifest !== null) {
                $grouped[(int) $claim->account_id][] = $manifest;
            }
        }

        return $grouped;
    }

    /** @param list<array<string, mixed>> $members
     * @return array<string, int>
     */
    private function counts(array $members): array
    {
        $counts = [];
        foreach (AudienceMemberResult::cases() as $result) {
            $matching = array_values(array_filter($members, fn (array $member): bool => $member['result'] === $result->value));
            $counts[$result->value.'_people'] = count($matching);
            $counts[$result->value.'_accounts'] = count(array_unique(array_column($matching, 'account_id')));
        }

        return $counts;
    }
}
