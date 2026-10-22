<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Intelligence\DTOs\QualificationEvaluation;
use App\Domain\Intelligence\Enums\QualificationDecisionClaimRole;
use App\Domain\Intelligence\Enums\QualificationOutcome;
use App\Domain\Intelligence\Enums\QualificationReviewState;
use App\Domain\Intelligence\Enums\QualificationRubricState;
use App\Domain\Intelligence\Exceptions\QualificationValidationException;
use App\Domain\Intelligence\Support\CanonicalIntelligence;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\Account;
use App\Models\AccountResearchRun;
use App\Models\AiProviderOperation;
use App\Models\QualificationDecision;
use App\Models\QualificationDecisionClaim;
use App\Models\QualificationRubric;
use App\Models\QualificationRubricVersion;
use App\Models\ReasonToContactClaim;
use App\Models\ReasonToContactVersion;
use App\Models\ResearchClaim;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Evaluate one Account against an exact rubric and projected Claim IDs.
 * Deterministic blockers always run before optional model interpretation.
 */
final class EvaluateAccountQualification
{
    public function __construct(
        private readonly ProjectAccountIntelligence $projection,
        private readonly EnsureQualificationRubric $rubrics,
    ) {}

    public function handle(
        Workspace $workspace,
        AccountResearchRun|Account|int|string $subject,
        QualificationRubricVersion|string|null $rubricVersion = null,
        ?QualificationEvaluation $evaluation = null,
        ?AiProviderOperation $providerOperation = null,
    ): QualificationDecision {
        $assessment = $this->assess($workspace, $subject, $rubricVersion);
        $computed = $evaluation ?? $assessment['deterministic'];

        return $this->persistAssessment($workspace, $assessment, $computed, $providerOperation);
    }

    /**
     * Resolve the immutable rubric and current projected claims once before
     * any optional model call. The returned assessment is the exact input
     * set that a provider result must be persisted against.
     *
     * @return array{
     *     run:AccountResearchRun,
     *     account:Account,
     *     rubric:QualificationRubricVersion,
     *     claims:list<array<string,mixed>>,
     *     accepted_claims:list<array<string,mixed>>,
     *     deterministic:QualificationEvaluation
     * }
     */
    public function assess(
        Workspace $workspace,
        AccountResearchRun|Account|int|string $subject,
        QualificationRubricVersion|string|null $rubricVersion = null,
    ): array {
        [$run, $account] = $this->resolveSubject($workspace, $subject);
        $rubric = $this->resolveRubric($workspace, $rubricVersion, $run);
        $this->assertActiveRubric($workspace, $rubric);
        $projected = $this->projection->handle($workspace, $account);
        $projectedClaims = $this->claimList($projected['claims'] ?? []);
        $acceptedClaims = array_values(array_filter(
            $projectedClaims,
            static fn (array $claim): bool => ($claim['eligible_for_qualification'] ?? false) === true,
        ));

        return [
            'run' => $run,
            'account' => $account,
            'rubric' => $rubric,
            'claims' => $projectedClaims,
            'accepted_claims' => $acceptedClaims,
            'deterministic' => $this->deterministicEvaluation($rubric, $this->claimsByPredicate($projectedClaims), $projectedClaims),
        ];
    }

    /**
     * Build the bounded provider input from only the immutable rubric and
     * projected accepted Claim handles/evidence references.
     *
     * @param  array{
     *     run:AccountResearchRun,
     *     account:Account,
     *     rubric:QualificationRubricVersion,
     *     claims:list<array<string,mixed>>,
     *     accepted_claims:list<array<string,mixed>>,
     *     deterministic:QualificationEvaluation
     * }  $assessment
     */
    public function providerPrompt(array $assessment): string
    {
        $rubric = $assessment['rubric'];
        $acceptedClaims = array_map(static fn (array $claim): array => [
            'claim_id' => (string) ($claim['claim_id'] ?? ''),
            'predicate' => (string) ($claim['predicate'] ?? ''),
            'value' => $claim['value'] ?? null,
            'value_type' => (string) ($claim['value_type'] ?? ''),
            'status' => (string) ($claim['status'] ?? ''),
            'confidence' => (float) ($claim['confidence'] ?? 0),
            'evidence_ids' => array_values(array_map('strval', is_array($claim['evidence_ids'] ?? null) ? $claim['evidence_ids'] : [])),
        ], $assessment['accepted_claims']);
        $payload = [
            'rubric' => [
                'version_id' => (string) $rubric->id,
                'content_hash' => (string) $rubric->content_hash,
                'dimensions' => $rubric->dimensions,
                'required_facts' => $rubric->required_facts,
                'disqualifiers' => $rubric->disqualifiers,
                'confidence_bands' => $rubric->confidence_bands,
                'sensitive_predicates' => $rubric->sensitive_predicates,
                'decision_rules' => $rubric->decision_rules,
            ],
            'accepted_claims' => $acceptedClaims,
        ];
        $encoded = CanonicalIntelligence::encode($payload);
        $maximumBytes = max(1, (int) config('ai.tasks.qualification.maximum_input_bytes', 80_000));
        if (strlen($encoded) > $maximumBytes) {
            throw new QualificationValidationException('The qualification provider input exceeds its bounded policy.');
        }

        return $encoded;
    }

    /**
     * Parse one strict qualification response and permit references only to
     * the accepted Claim IDs supplied in the provider prompt.
     *
     * @param  list<array<string,mixed>>  $acceptedClaims
     */
    public function providerEvaluation(string $output, array $acceptedClaims): QualificationEvaluation
    {
        try {
            $decoded = CanonicalIntelligence::decodeObject($output);
        } catch (\Throwable $exception) {
            throw new QualificationValidationException('The qualification output was not valid JSON.', 0, $exception);
        }
        $allowed = ['outcome', 'confidence', 'reasons', 'missing_facts', 'disqualifiers', 'claim_ids', 'prohibited_inference', 'unsupported_causality'];
        $unexpected = array_diff(array_keys($decoded), $allowed);
        if ($unexpected !== []) {
            throw new QualificationValidationException('The qualification output contains unsupported fields.');
        }
        foreach ($allowed as $required) {
            if (! array_key_exists($required, $decoded)) {
                throw new QualificationValidationException("The qualification output is missing {$required}.");
            }
        }
        $outcome = is_string($decoded['outcome'] ?? null)
            ? QualificationOutcome::tryFrom($decoded['outcome'])
            : null;
        if ($outcome === null) {
            throw new QualificationValidationException('The qualification outcome is not one of the approved states.');
        }
        $confidence = $decoded['confidence'] ?? null;
        if ((is_int($confidence) || is_float($confidence)) && is_finite((float) $confidence) && $confidence >= 0 && $confidence <= 1) {
            $confidence = (float) $confidence;
        } else {
            throw new QualificationValidationException('Qualification confidence must be between zero and one.');
        }
        $reasons = $this->stringList($decoded['reasons'], 'reasons', 20, 1000);
        if ($reasons === []) {
            throw new QualificationValidationException('The qualification output must include at least one reason.');
        }
        $missingFacts = $this->stringList($decoded['missing_facts'], 'missing_facts', 50, 160);
        $disqualifiers = $this->stringList($decoded['disqualifiers'], 'disqualifiers', 50, 160);
        $claimIds = $this->stringList($decoded['claim_ids'], 'claim_ids', 100, 160);
        $prohibitedInference = $decoded['prohibited_inference'];
        $unsupportedCausality = $decoded['unsupported_causality'];
        if (! is_bool($prohibitedInference) || ! is_bool($unsupportedCausality)) {
            throw new QualificationValidationException('Qualification safety flags must be boolean.');
        }
        $acceptedIds = array_fill_keys(array_map(
            static fn (array $claim): string => (string) ($claim['claim_id'] ?? ''),
            $acceptedClaims,
        ), true);
        foreach ($claimIds as $claimId) {
            if ($claimId === '' || ! isset($acceptedIds[$claimId])) {
                throw new QualificationValidationException('The qualification output cites a Claim outside the accepted input set.');
            }
        }
        if ($claimIds === []) {
            throw new QualificationValidationException('The qualification output must cite at least one accepted Claim.');
        }

        return new QualificationEvaluation(
            outcome: $outcome,
            confidence: $confidence,
            calibrationBand: $this->band($confidence),
            missingFacts: $missingFacts,
            disqualifiers: $disqualifiers,
            explanation: implode(' ', $reasons),
            claims: array_map(static fn (string $claimId): array => [
                'claim_id' => $claimId,
                'role' => QualificationDecisionClaimRole::Supporting->value,
            ], $claimIds),
            sensitiveInference: $prohibitedInference,
            unsupportedCausality: $unsupportedCausality,
        );
    }

    /**
     * @param  array{
     *     run:AccountResearchRun,
     *     account:Account,
     *     rubric:QualificationRubricVersion,
     *     claims:list<array<string,mixed>>,
     *     accepted_claims:list<array<string,mixed>>,
     *     deterministic:QualificationEvaluation
     * }  $assessment
     */
    public function persistAssessment(
        Workspace $workspace,
        array $assessment,
        QualificationEvaluation $computed,
        ?AiProviderOperation $providerOperation = null,
    ): QualificationDecision {
        $run = $assessment['run'];
        $account = $assessment['account'];
        $rubric = $assessment['rubric'];
        $this->assertActiveRubric($workspace, $rubric);
        if ($providerOperation !== null) {
            $this->assertProviderOperation($workspace, $run, $providerOperation);
            $computed = $this->reconcileProviderEvaluation($assessment['deterministic'], $computed, $rubric);
        }
        $this->assertEvaluationClaims(
            $computed,
            $providerOperation === null ? $assessment['claims'] : $assessment['accepted_claims'],
        );
        $claimIds = array_values(array_unique(array_map(static fn (array $claim): string => (string) $claim['claim_id'], $computed->claims)));
        $inputHash = CanonicalIntelligence::hash([
            'run_id' => (string) $run->id,
            'rubric_version_id' => (string) $rubric->id,
            'claims' => $claimIds,
            'outcome' => $computed->outcome->value,
            'confidence' => $computed->confidence,
            'calibration_band' => $computed->calibrationBand,
            'missing_facts' => $computed->missingFacts,
            'disqualifiers' => $computed->disqualifiers,
            'explanation' => $computed->explanation,
        ]);

        return DB::transaction(function () use ($workspace, $run, $account, $rubric, $computed, $inputHash, $claimIds, $providerOperation): QualificationDecision {
            $this->assertActiveRubric($workspace, $rubric);
            $existing = QualificationDecision::query()
                ->where('workspace_id', $workspace->id)
                ->where('research_run_id', $run->id)
                ->where('rubric_version_id', $rubric->id)
                ->where('input_hash', $inputHash)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
            $contentHash = CanonicalIntelligence::hash([
                'input_hash' => $inputHash,
                'outcome' => $computed->outcome->value,
                'confidence' => $computed->confidence,
                'calibration_band' => $computed->calibrationBand,
                'missing_facts' => $computed->missingFacts,
                'disqualifiers' => $computed->disqualifiers,
                'explanation' => $computed->explanation,
                'claims' => $computed->claims,
            ]);
            $decision = QualificationDecision::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'account_id' => $account->id,
                'research_run_id' => $run->id,
                'rubric_version_id' => $rubric->id,
                'gtm_profile_version_id' => $run->gtm_profile_version_id,
                'offer_version_id' => $run->offer_version_id,
                'prior_decision_id' => null,
                'input_hash' => $inputHash,
                'outcome' => $computed->outcome,
                'confidence' => max(0, min(1, $computed->confidence)),
                'calibration_band' => $computed->calibrationBand ?? $this->band($computed->confidence),
                'missing_facts' => $computed->missingFacts,
                'disqualifiers' => $computed->disqualifiers,
                'explanation' => mb_substr($computed->explanation, 0, (int) config('intelligence.qualification.max_explanation_bytes', 4000)),
                'model_version' => $providerOperation?->model_or_endpoint,
                'prompt_version' => $providerOperation === null ? null : 'qualification-v1',
                'policy_version' => $providerOperation === null ? 'deterministic-v1' : (string) $providerOperation->policy_version,
                'catalogue_version' => $providerOperation === null ? null : (string) config('ai.catalogue_version', '2026-09-03'),
                'provider_operation_id' => $providerOperation?->id,
                'provider_cost_micros' => $providerOperation === null ? 0 : (int) ($providerOperation->actual_cost_micros ?? 0),
                'review_state' => QualificationReviewState::Pending,
                'content_hash' => $contentHash,
                'generated_at' => now(),
                'reviewed_at' => null,
                'corrected_at' => null,
            ]);
            foreach ($computed->claims as $link) {
                if (! in_array($link['claim_id'], $claimIds, true)) {
                    continue;
                }
                QualificationDecisionClaim::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'decision_id' => $decision->id,
                    'claim_id' => $link['claim_id'],
                    'role' => $link['role'],
                ]);
            }
            $this->writeReason($workspace, $account, $decision, $computed, $claimIds);

            return $decision->fresh();
        }, 3);
    }

    /**
     * A model may explain an outcome, but it cannot silently override the
     * deterministic rubric. Any disagreement or unsafe contact becomes an
     * explicit review state while preserving the provider result lineage.
     */
    private function reconcileProviderEvaluation(
        QualificationEvaluation $deterministic,
        QualificationEvaluation $provider,
        QualificationRubricVersion $rubric,
    ): QualificationEvaluation {
        $contactThreshold = (float) data_get($rubric->decision_rules, 'contact_min_confidence', 0.75);
        $outcomesDisagree = $deterministic->outcome !== $provider->outcome;
        $unsafeContact = $provider->outcome === QualificationOutcome::Contact
            && ($provider->missingFacts !== []
                || $provider->disqualifiers !== []
                || $provider->sensitiveInference
                || $provider->unsupportedCausality
                || $provider->confidence < $contactThreshold);
        $requiresReview = $outcomesDisagree || $unsafeContact;
        if (! $requiresReview) {
            return $provider;
        }
        if ($provider->outcome === QualificationOutcome::NeedsReview) {
            return $provider;
        }

        return new QualificationEvaluation(
            outcome: QualificationOutcome::NeedsReview,
            confidence: $provider->confidence,
            calibrationBand: $provider->calibrationBand,
            missingFacts: $provider->missingFacts,
            disqualifiers: $provider->disqualifiers,
            explanation: 'The provider qualification outcome did not satisfy the deterministic rubric gate. '.$provider->explanation,
            claims: $provider->claims,
            sensitiveInference: $provider->sensitiveInference,
            unsupportedCausality: $provider->unsupportedCausality,
            factualBasis: $provider->factualBasis,
            internalRationale: $provider->internalRationale,
        );
    }

    public function evaluate(
        Workspace $workspace,
        AccountResearchRun|Account|int|string $subject,
        QualificationRubricVersion|string|null $rubricVersion = null,
        ?QualificationEvaluation $evaluation = null,
    ): QualificationDecision {
        return $this->handle($workspace, $subject, $rubricVersion, $evaluation);
    }

    private function assertActiveRubric(Workspace $workspace, QualificationRubricVersion $rubric): void
    {
        $state = QualificationRubric::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($rubric->rubric_id)
            ->value('state');
        if (ModelAttribute::enumValue($state) !== QualificationRubricState::Active->value) {
            throw new QualificationValidationException('Only an active rubric can produce a decision.');
        }
    }

    /** @param list<array<string,mixed>> $allowedClaims */
    private function assertEvaluationClaims(QualificationEvaluation $evaluation, array $allowedClaims): void
    {
        $allowedIds = array_fill_keys(array_map(
            static fn (array $claim): string => (string) ($claim['claim_id'] ?? ''),
            $allowedClaims,
        ), true);
        $roles = array_map(static fn (QualificationDecisionClaimRole $role): string => $role->value, QualificationDecisionClaimRole::cases());
        $seen = [];
        foreach ($evaluation->claims as $link) {
            $claimId = $link['claim_id'];
            $role = $link['role'];
            if ($claimId === '' || ! isset($allowedIds[$claimId]) || ! in_array($role, $roles, true)) {
                throw new QualificationValidationException('The qualification decision cites an invalid Claim or role.');
            }
            $key = $claimId.':'.$role;
            if (isset($seen[$key])) {
                throw new QualificationValidationException('The qualification decision repeats a Claim citation.');
            }
            $seen[$key] = true;
        }
    }

    private function assertProviderOperation(Workspace $workspace, AccountResearchRun $run, AiProviderOperation $operation): void
    {
        if ((int) $operation->workspace_id !== (int) $workspace->id
            || ModelAttribute::enumValue($operation->task) !== AiTask::Qualification->value
            || $run->qualification_budget_run_id === null
            || (string) $operation->budget_run_id !== (string) $run->qualification_budget_run_id
            || $operation->status !== AiOperationStatus::Succeeded
            || $operation->actual_cost_micros === null) {
            throw new QualificationValidationException('The qualification provider operation is not an exact settled result.');
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $field, int $maximumItems, int $maximumBytes): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maximumItems) {
            throw new QualificationValidationException("The qualification {$field} list is invalid.");
        }
        $result = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '' || strlen($item) > $maximumBytes) {
                throw new QualificationValidationException("The qualification {$field} list contains an invalid item.");
            }
            $result[] = $item;
        }

        return array_values(array_unique($result));
    }

    /** @return array{AccountResearchRun,Account} */
    private function resolveSubject(Workspace $workspace, AccountResearchRun|Account|int|string $subject): array
    {
        if ($subject instanceof AccountResearchRun) {
            $run = AccountResearchRun::query()->where('workspace_id', $workspace->id)->whereKey($subject->id)->firstOrFail();
            $account = Account::query()->where('workspace_id', $workspace->id)->whereKey($run->account_id)->firstOrFail();

            return [$run, $account];
        }
        $account = $subject instanceof Account
            ? Account::query()->where('workspace_id', $workspace->id)->whereKey($subject->id)->firstOrFail()
            : Account::query()->where('workspace_id', $workspace->id)->whereKey($subject)->firstOrFail();
        $run = AccountResearchRun::query()
            ->where('workspace_id', $workspace->id)
            ->where('account_id', $account->id)
            ->orderByDesc('created_at')
            ->first();
        if ($run === null) {
            throw new QualificationValidationException('An Account research run is required before qualification.');
        }

        return [$run, $account];
    }

    private function resolveRubric(Workspace $workspace, QualificationRubricVersion|string|null $requested, AccountResearchRun $run): QualificationRubricVersion
    {
        if ($requested instanceof QualificationRubricVersion) {
            return QualificationRubricVersion::query()->where('workspace_id', $workspace->id)->whereKey($requested->id)->firstOrFail();
        }
        if (is_string($requested)) {
            return QualificationRubricVersion::query()->where('workspace_id', $workspace->id)->whereKey($requested)->firstOrFail();
        }
        $profile = $run->gtmProfileVersion;
        $offer = $run->offerVersion;

        return $this->rubrics->handle($workspace, null, $profile, $offer);
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $claimsByPredicate
     * @param  list<array<string,mixed>>  $flatClaims
     */
    private function deterministicEvaluation(QualificationRubricVersion $rubric, array $claimsByPredicate, array $flatClaims): QualificationEvaluation
    {
        $required = ModelAttribute::array($rubric->required_facts);
        $missing = [];
        $links = [];
        foreach ($required as $fact) {
            $predicate = is_string($fact) ? $fact : (is_array($fact) ? (string) ($fact['predicate'] ?? $fact['key'] ?? '') : '');
            if ($predicate === '') {
                continue;
            }
            $candidates = $claimsByPredicate[$predicate] ?? [];
            $usable = array_values(array_filter($candidates, static fn (array $claim): bool => in_array($claim['status'], ['supported', 'inferred', 'owner_confirmed'], true)));
            if ($usable === []) {
                $missing[] = $predicate;

                continue;
            }
            foreach ($usable as $claim) {
                $links[] = ['claim_id' => $claim['claim_id'], 'role' => QualificationDecisionClaimRole::Required->value];
            }
        }

        $disqualifiers = [];
        foreach (ModelAttribute::array($rubric->disqualifiers) as $rule) {
            $predicate = is_string($rule) ? $rule : (is_array($rule) ? (string) ($rule['predicate'] ?? '') : '');
            if ($predicate === '') {
                continue;
            }
            foreach ($claimsByPredicate[$predicate] ?? [] as $claim) {
                if (in_array($claim['status'], ['supported', 'owner_confirmed'], true)) {
                    $disqualifiers[] = $predicate;
                    $links[] = ['claim_id' => $claim['claim_id'], 'role' => QualificationDecisionClaimRole::Disqualifying->value];
                }
            }
        }
        $conflicted = array_values(array_filter($flatClaims, static fn (array $claim): bool => in_array($claim['status'], ['conflicting', 'stale'], true)));
        foreach ($conflicted as $claim) {
            $links[] = ['claim_id' => $claim['claim_id'], 'role' => $claim['status'] === 'conflicting' ? QualificationDecisionClaimRole::Contradicting->value : QualificationDecisionClaimRole::Missing->value];
        }
        $sensitive = array_values(array_filter($flatClaims, static fn (array $claim): bool => preg_match('/(?:race|religion|health|politic|gender|age|protected)/i', (string) $claim['predicate']) === 1));
        $confidence = $this->confidence($flatClaims);

        if ($sensitive !== []) {
            return new QualificationEvaluation(QualificationOutcome::NeedsReview, $confidence, $this->band($confidence), explanation: 'A prohibited or sensitive inference requires human review.', claims: $links, sensitiveInference: true, deterministicBlocker: true);
        }
        if ($conflicted !== []) {
            return new QualificationEvaluation(QualificationOutcome::NeedsReview, $confidence, $this->band($confidence), explanation: 'Material Claim conflict or stale evidence requires human review.', claims: $links, deterministicBlocker: true);
        }
        if ($disqualifiers !== []) {
            return new QualificationEvaluation(QualificationOutcome::Withhold, $confidence, $this->band($confidence), disqualifiers: array_values(array_unique($disqualifiers)), explanation: 'An evidenced disqualifier means this Account should be withheld.', claims: $links, deterministicBlocker: true);
        }
        if ($missing !== []) {
            foreach ($missing as $predicate) {
                $links[] = ['claim_id' => 'missing:'.$predicate, 'role' => QualificationDecisionClaimRole::Missing->value];
            }

            if ($flatClaims === []) {
                return new QualificationEvaluation(QualificationOutcome::InsufficientEvidence, $confidence, $this->band($confidence), missingFacts: $missing, explanation: 'The bounded sources do not support the required facts.', claims: array_values(array_filter($links, static fn (array $link): bool => ! str_starts_with($link['claim_id'], 'missing:'))), deterministicBlocker: true);
            }

            return new QualificationEvaluation(QualificationOutcome::ResearchFurther, $confidence, $this->band($confidence), missingFacts: $missing, explanation: 'Named facts are missing and can be requested in a bounded follow-up.', claims: array_values(array_filter($links, static fn (array $link): bool => ! str_starts_with($link['claim_id'], 'missing:'))), deterministicBlocker: true);
        }
        $threshold = (float) data_get($rubric->decision_rules, 'contact_min_confidence', 0.75);
        if ($confidence < $threshold) {
            return new QualificationEvaluation(QualificationOutcome::NeedsReview, $confidence, $this->band($confidence), explanation: 'The evidence does not meet the rubric confidence band for contact.', claims: $links, deterministicBlocker: $flatClaims === []);
        }

        return new QualificationEvaluation(QualificationOutcome::Contact, $confidence, $this->band($confidence), explanation: 'All rubric requirements have supported evidence and no disqualifier was found.', claims: $links, whyAccount: 'The Account matches the rubric requirements.', whyOffer: 'The current Offer context is associated with this decision.', whyNow: 'The supplied evidence is current within the research freshness policy.', factualBasis: 'Every basis is linked to an immutable Claim.', internalRationale: 'Review remains required before any downstream action.');
    }

    /**
     * @param  list<array<string,mixed>>  $claims
     * @return array<string,list<array<string,mixed>>>
     */
    private function claimsByPredicate(array $claims): array
    {
        $grouped = [];
        foreach ($claims as $claim) {
            $predicate = $claim['predicate'] ?? null;
            if (! is_string($predicate) || $predicate === '') {
                continue;
            }
            $grouped[$predicate][] = $claim;
        }

        return $grouped;
    }

    /** @return list<array<string,mixed>> */
    private function claimList(mixed $claims): array
    {
        if (! is_array($claims)) {
            return [];
        }

        $result = [];
        foreach ($claims as $claim) {
            if (is_array($claim)) {
                $result[] = $claim;
            }
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $claims */
    private function confidence(array $claims): float
    {
        if ($claims === []) {
            return 0;
        }
        $values = array_map(static fn (array $claim): float => (float) ($claim['confidence'] ?? 0), $claims);

        return max(0, min(1, array_sum($values) / count($values)));
    }

    private function band(float $confidence): string
    {
        return $confidence >= 0.75 ? 'high' : ($confidence >= 0.5 ? 'medium' : 'low');
    }

    /** @param list<string> $claimIds */
    private function writeReason(Workspace $workspace, Account $account, QualificationDecision $decision, QualificationEvaluation $evaluation, array $claimIds): void
    {
        if ($evaluation->whyAccount === null && $evaluation->whyOffer === null && $evaluation->whyNow === null && $evaluation->factualBasis === null && $evaluation->internalRationale === null) {
            return;
        }
        $contentHash = CanonicalIntelligence::hash([
            'decision_id' => (string) $decision->id,
            'why_account' => $evaluation->whyAccount,
            'why_offer' => $evaluation->whyOffer,
            'why_now' => $evaluation->whyNow,
            'factual_basis' => $evaluation->factualBasis,
            'internal_rationale' => $evaluation->internalRationale,
            'claim_ids' => $claimIds,
        ]);
        $reason = ReasonToContactVersion::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'account_id' => $account->id,
            'qualification_decision_id' => $decision->id,
            'successor_id' => null,
            'why_account' => $evaluation->whyAccount,
            'why_offer' => $evaluation->whyOffer,
            'why_now' => $evaluation->whyNow,
            'factual_basis' => $evaluation->factualBasis,
            'internal_rationale' => $evaluation->internalRationale,
            'confidence' => $evaluation->confidence,
            'fresh_until' => now()->addHours(24),
            'expires_at' => null,
            'safe_for_message' => false,
            'sensitive_inference' => $evaluation->sensitiveInference,
            'prohibited_inference' => $evaluation->sensitiveInference,
            'content_hash' => $contentHash,
        ]);
        foreach ($claimIds as $claimId) {
            if (! ResearchClaim::query()->where('workspace_id', $workspace->id)->whereKey($claimId)->exists()) {
                continue;
            }
            ReasonToContactClaim::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'reason_to_contact_version_id' => $reason->id,
                'claim_id' => $claimId,
                'role' => 'basis',
            ]);
        }
    }
}
