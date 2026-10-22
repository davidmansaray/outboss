<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Ai\Enums\AiTask;
use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Objectives\Exceptions\DraftMessageValidationException;
use App\Domain\Objectives\Exceptions\MessageDraftingEvaluationException;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\DeterministicMessageTemplate;
use App\Domain\Objectives\Support\MessageEvidenceContract;
use JsonException;

/**
 * Replay the checked-in S3 message-drafting corpus without a provider or DB.
 *
 * The response fixtures are deliberately evaluation inputs, not a provider
 * adapter and not evidence that the target provider passed the corpus.
 */
final class RunMessageDraftingEvaluation
{
    public const VERSION = 'message_drafting_evaluation_runner_v1';

    private const TASK_VERSION = 'message_drafting_v4';

    /** @var list<string> */
    private const REQUIRED_COVERAGE = [
        'causal_prose',
        'conflicting_evidence_abstention',
        'cta_mismatch',
        'destination_mismatch',
        'deterministic_render_safety',
        'false_familiarity',
        'insufficient_evidence_abstention',
        'invented_prose',
        'length_limit',
        'malformed_schema',
        'offer_mismatch',
        'placeholder_safety',
        'prohibited_personalization',
        'readiness_abstention',
        'rights_abstention',
        'suppression_abstention',
        'supported_exact_reason_claim_choice',
        'unknown_choice',
        'unlinked_choice',
        'unsafe_prose',
        'wrong_choice',
    ];

    /** @var list<string> */
    private const METRICS = [
        'abstention_recall',
        'human_escalation_rate',
        'offer_compliance_precision',
        'schema_validity',
        'supported_claim_precision',
        'unsafe_or_fabricated_contact_count',
    ];

    private string $evaluationRoot;

    public function __construct(
        private readonly ValidateDraftedMessage $validator,
        private readonly DeterministicMessageTemplate $templates,
        private readonly RenderSequenceMessage $renderer,
        ?string $evaluationRoot = null,
    ) {
        $this->evaluationRoot = $evaluationRoot ?? resource_path('evaluations/message-drafting');
    }

    /** @return array<string, string> */
    public function runtimeBindings(): array
    {
        $configuredValidator = config('ai.tasks.message_drafting.validator_version');
        $schemaVersion = config('ai.tasks.message_drafting.response_schema.version');
        $model = config('ai.tasks.message_drafting.model_id');
        if (! is_string($configuredValidator) || ! is_string($schemaVersion) || ! is_string($model)
            || $configuredValidator === '' || $schemaVersion === '' || $model === '') {
            throw $this->invalid('runtime_configuration_invalid', 'The active message-drafting runtime configuration is incomplete.');
        }
        if (! hash_equals(ValidateDraftedMessage::VERSION, $configuredValidator)) {
            throw $this->invalid('runtime_version_drift', 'The configured message validator does not match the executable validator.');
        }

        return [
            'evidence_contract_version' => MessageEvidenceContract::VERSION,
            'message_contract_version' => BuildObjectiveDraftPrompt::MESSAGE_CONTRACT_VERSION,
            'model' => $model,
            'plan_validator_version' => ValidateExecutionPlan::VERSION,
            'prompt_version' => BuildObjectiveDraftPrompt::VERSION,
            'provider' => 'openrouter',
            'response_schema_version' => $schemaVersion,
            'task' => AiTask::MessageDrafting->value,
            'task_version' => self::TASK_VERSION,
            'template_version' => DeterministicMessageTemplate::VERSION,
            'validator_version' => ValidateDraftedMessage::VERSION,
        ];
    }

    /** @return array<string, mixed> */
    public function handle(string $dataset = 'v1', string $responses = 'deterministic'): array
    {
        if (preg_match('/^v[1-9][0-9]*$/', $dataset) !== 1) {
            throw $this->invalid('dataset_invalid', 'The dataset must be a version such as v1.');
        }
        if ($responses !== 'deterministic') {
            throw $this->invalid('response_source_invalid', 'Only the deterministic offline response replay is supported.');
        }

        $directory = $this->evaluationRoot.'/'.$dataset;
        $manifest = $this->readJson($directory.'/manifest.json', 'manifest');
        $corpus = $this->readJson($directory.'/cases.json', 'cases');
        $approval = $this->readJson($directory.'/approval.json', 'approval');
        $replay = $this->readJson($directory.'/responses/'.$responses.'.json', 'responses');
        $runtime = $this->runtimeBindings();

        $this->assertExactKeys($manifest, [
            'schema_version', 'dataset_version', 'description', 'case_count', 'case_set_hash', 'runtime_bindings',
        ], 'manifest');
        if (($manifest['schema_version'] ?? null) !== 'message_drafting_evaluation_manifest_v1'
            || ($manifest['dataset_version'] ?? null) !== $dataset
            || ! is_string($manifest['description'] ?? null)
            || trim((string) $manifest['description']) === ''
            || ! is_int($manifest['case_count'] ?? null)
            || ! is_string($manifest['case_set_hash'] ?? null)) {
            throw $this->invalid('manifest_invalid', 'The evaluation manifest is invalid.');
        }
        $this->assertBindings($manifest['runtime_bindings'] ?? null, $runtime, 'manifest');

        $this->assertExactKeys($corpus, ['schema_version', 'dataset_version', 'cases'], 'cases');
        $cases = $corpus['cases'] ?? null;
        if (($corpus['schema_version'] ?? null) !== 'message_drafting_evaluation_cases_v1'
            || ($corpus['dataset_version'] ?? null) !== $dataset
            || ! is_array($cases) || ! array_is_list($cases) || $cases === []) {
            throw $this->invalid('cases_invalid', 'The evaluation cases file is invalid.');
        }

        $caseMap = [];
        $caseHashes = [];
        $semanticHashes = [];
        $coverage = [];
        foreach ($cases as $case) {
            if (! is_array($case) || array_is_list($case)) {
                throw $this->invalid('case_invalid', 'An evaluation case is not an object.');
            }
            $this->validateCase($case, $runtime);
            $caseId = (string) $case['case_id'];
            if (isset($caseMap[$caseId])) {
                throw $this->invalid('duplicate_case_id', 'The evaluation corpus contains a duplicate case ID.');
            }
            $declaredHash = (string) $case['case_hash'];
            $hashInput = $case;
            unset($hashInput['case_hash']);
            if (! hash_equals($declaredHash, CanonicalObjectivePayload::hash($hashInput))) {
                throw $this->invalid('case_hash_mismatch', 'An evaluation case does not match its declared hash.');
            }
            if (isset($caseHashes[$declaredHash])) {
                throw $this->invalid('duplicate_case', 'The evaluation corpus contains duplicate cases.');
            }
            $semanticInput = $hashInput;
            unset($semanticInput['case_id'], $semanticInput['description']);
            $semanticHash = CanonicalObjectivePayload::hash($semanticInput);
            if (isset($semanticHashes[$semanticHash])) {
                throw $this->invalid('duplicate_case', 'The evaluation corpus contains semantically duplicate cases.');
            }
            $caseMap[$caseId] = $case;
            $caseHashes[$declaredHash] = true;
            $semanticHashes[$semanticHash] = true;
            foreach ($case['coverage'] as $tag) {
                $coverage[$tag] = true;
            }
        }
        ksort($caseMap, SORT_STRING);
        if ((int) $manifest['case_count'] !== count($caseMap)) {
            throw $this->invalid('case_count_mismatch', 'The manifest case count does not match the corpus.');
        }
        $missingCoverage = array_values(array_diff(self::REQUIRED_COVERAGE, array_keys($coverage)));
        if ($missingCoverage !== []) {
            throw $this->invalid('coverage_incomplete', 'The evaluation corpus is missing required coverage.');
        }
        $caseSetHash = $this->caseSetHash($caseMap);
        if (! hash_equals((string) $manifest['case_set_hash'], $caseSetHash)) {
            throw $this->invalid('case_set_hash_mismatch', 'The manifest does not bind the exact evaluation cases.');
        }

        $this->validateApproval($approval, $dataset, $caseSetHash);
        $responseMap = $this->validateReplay($replay, $dataset, $caseSetHash, array_keys($caseMap));

        $results = [];
        foreach ($caseMap as $caseId => $case) {
            $results[] = $this->evaluateCase($case, $responseMap[$caseId]);
        }
        $metrics = $this->metrics($results);
        $expectationFailures = count(array_filter(
            $results,
            static fn (array $result): bool => ! $result['expectation_met'],
        ));
        $gateReasons = ['owner_threshold_approval_missing', 'target_provider_evidence_missing'];
        if ($expectationFailures > 0) {
            $gateReasons[] = 'deterministic_fixture_expectation_failures';
        }
        sort($gateReasons, SORT_STRING);

        $report = [
            'approval' => [
                'case_set_hash' => $caseSetHash,
                'status' => 'pending_owner_approval',
                'thresholds' => array_fill_keys(self::METRICS, null),
            ],
            'case_count' => count($results),
            'case_set_hash' => $caseSetHash,
            'cases' => $results,
            'dataset_version' => $dataset,
            'evaluation_version' => self::VERSION,
            'expectation_failure_count' => $expectationFailures,
            'metrics' => $metrics,
            'provenance' => [
                'fixture_version' => (string) $replay['provenance']['fixture_version'],
                'kind' => 'deterministic_fixture',
                'target_provider_evidence' => false,
            ],
            'release_gate_reasons' => $gateReasons,
            'release_gate_status' => 'blocked',
            'response_set' => $responses,
            'runtime_bindings' => $runtime,
        ];
        $report['result_hash'] = CanonicalObjectivePayload::hash($report);

        /** @var array<string, mixed> $canonical */
        $canonical = CanonicalObjectivePayload::canonicalize($report);

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, string>  $runtime
     */
    private function validateCase(array $case, array $runtime): void
    {
        $this->assertExactKeys($case, [
            'case_id', 'description', 'coverage', 'runtime_bindings', 'label', 'contract', 'render_input', 'case_hash',
        ], 'case');
        if (! is_string($case['case_id'] ?? null)
            || preg_match('/^[a-z0-9][a-z0-9_-]{2,79}$/', (string) $case['case_id']) !== 1
            || ! is_string($case['description'] ?? null)
            || trim((string) $case['description']) === ''
            || strlen((string) $case['description']) > 300
            || ! is_string($case['case_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', (string) $case['case_hash']) !== 1
            || ! is_array($case['coverage'] ?? null)
            || ! array_is_list($case['coverage'])
            || $case['coverage'] === []) {
            throw $this->invalid('case_invalid', 'An evaluation case has invalid metadata.');
        }
        $normalizedCoverage = [];
        foreach ($case['coverage'] as $tag) {
            if (! is_string($tag) || ! in_array($tag, self::REQUIRED_COVERAGE, true)) {
                throw $this->invalid('case_coverage_invalid', 'An evaluation case has an unknown coverage tag.');
            }
            $normalizedCoverage[] = $tag;
        }
        $sortedCoverage = array_values(array_unique($normalizedCoverage));
        sort($sortedCoverage, SORT_STRING);
        if ($case['coverage'] !== $sortedCoverage) {
            throw $this->invalid('case_coverage_invalid', 'Evaluation case coverage must be unique and sorted.');
        }
        $this->assertBindings($case['runtime_bindings'] ?? null, $runtime, 'case');

        $label = $case['label'] ?? null;
        if (! is_array($label) || array_is_list($label)) {
            throw $this->invalid('case_label_invalid', 'An evaluation case label is invalid.');
        }
        $this->assertExactKeys($label, [
            'status', 'expected_outcome', 'supported_choice_ids', 'requires_abstention',
            'contact_must_not_execute',
        ], 'case label');
        if (($label['status'] ?? null) !== 'pending_owner_approval'
            || ! in_array($label['expected_outcome'] ?? null, ['contact', 'abstain', 'rejected'], true)
            || ! is_bool($label['requires_abstention'] ?? null)
            || ! is_bool($label['contact_must_not_execute'] ?? null)
            || ! is_array($label['supported_choice_ids'] ?? null)
            || ! array_is_list($label['supported_choice_ids'])) {
            throw $this->invalid('case_label_invalid', 'An evaluation case label must remain explicitly pending owner approval.');
        }
        $choices = [];
        foreach ($label['supported_choice_ids'] as $choice) {
            if (! is_string($choice) || preg_match('/^evidence_v1_[a-f0-9]{64}$/', $choice) !== 1) {
                throw $this->invalid('case_label_invalid', 'A labelled supported choice is invalid.');
            }
            $choices[] = $choice;
        }
        $sortedChoices = array_values(array_unique($choices));
        sort($sortedChoices, SORT_STRING);
        if ($label['supported_choice_ids'] !== $sortedChoices
            || (($label['expected_outcome'] === 'abstain') !== $label['requires_abstention'])) {
            throw $this->invalid('case_label_invalid', 'An evaluation case label is inconsistent.');
        }

        if (! is_array($case['contract'] ?? null) || array_is_list($case['contract'])) {
            throw $this->invalid('case_contract_invalid', 'An evaluation case contract is invalid.');
        }
        $renderInput = $case['render_input'] ?? null;
        if (! is_array($renderInput) || array_is_list($renderInput)) {
            throw $this->invalid('case_render_input_invalid', 'An evaluation case render input is invalid.');
        }
        $this->assertExactKeys($renderInput, ['claim', 'recipient', 'maximum_words'], 'render input');
        if (! is_array($renderInput['claim'] ?? null) || array_is_list($renderInput['claim'])
            || ! is_array($renderInput['recipient'] ?? null) || array_is_list($renderInput['recipient'])
            || ! is_int($renderInput['maximum_words'] ?? null)
            || $renderInput['maximum_words'] < 40 || $renderInput['maximum_words'] > 250) {
            throw $this->invalid('case_render_input_invalid', 'An evaluation case render input is invalid.');
        }
        $this->assertExactKeys($renderInput['claim'], ['predicate', 'message_value'], 'render claim');
        $this->assertExactKeys($renderInput['recipient'], ['first_name', 'full_name', 'company'], 'render recipient');
        if (! is_string($renderInput['claim']['predicate'] ?? null)
            || ! is_string($renderInput['claim']['message_value'] ?? null)
            || (! is_string($renderInput['recipient']['first_name'] ?? null) && $renderInput['recipient']['first_name'] !== null)
            || (! is_string($renderInput['recipient']['full_name'] ?? null) && $renderInput['recipient']['full_name'] !== null)
            || ! is_string($renderInput['recipient']['company'] ?? null)) {
            throw $this->invalid('case_render_input_invalid', 'An evaluation case render input has invalid field types.');
        }
        $contractChoiceIds = $this->validateFrozenContract($case['contract'], $renderInput['maximum_words']);
        if (array_diff($label['supported_choice_ids'], $contractChoiceIds) !== []) {
            throw $this->invalid('case_label_invalid', 'A labelled supported choice is absent from the frozen contract.');
        }
    }

    /**
     * Exercise the current validator against every declared choice so a
     * malformed frozen contract is a corpus error, not a scored rejection.
     *
     * @param  array<string, mixed>  $contract
     * @return list<string>
     */
    private function validateFrozenContract(array $contract, int $maximumWords): array
    {
        $this->assertExactKeys($contract, [
            'contract_version', 'template_version', 'message_pattern', 'workspace_id',
            'strategy_version_id', 'strategy', 'offer', 'evidence_choices',
        ], 'frozen message contract');
        $choices = $contract['evidence_choices'] ?? null;
        if (! is_array($choices) || ! array_is_list($choices) || $choices === []) {
            throw $this->invalid('case_contract_invalid', 'A frozen message contract has no evidence choices.');
        }

        $choiceIds = [];
        foreach ($choices as $choice) {
            if (! is_array($choice) || array_is_list($choice) || ! is_string($choice['choice_id'] ?? null)) {
                throw $this->invalid('case_contract_invalid', 'A frozen message contract has an invalid evidence choice.');
            }
            $choiceId = $choice['choice_id'];
            if (in_array($choiceId, $choiceIds, true)) {
                throw $this->invalid('case_contract_invalid', 'A frozen message contract has a duplicate evidence choice.');
            }
            $candidate = json_encode([
                'message_pattern' => DeterministicMessageTemplate::PATTERN,
                'evidence_choice' => $choiceId,
                'cta_kind' => 'offer_action',
                'abstain' => false,
                'abstention_reasons' => [],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            try {
                $validated = $this->validator->handle($candidate, $contract, $maximumWords);
            } catch (DraftMessageValidationException) {
                throw $this->invalid('case_contract_invalid', 'A frozen message contract is not accepted by the current validator.');
            }
            if ($validated->abstain || ! hash_equals($choiceId, $validated->evidenceChoice)) {
                throw $this->invalid('case_contract_invalid', 'A frozen message contract choice did not round-trip through the current validator.');
            }
            $choiceIds[] = $choiceId;
        }
        sort($choiceIds, SORT_STRING);

        return $choiceIds;
    }

    /** @param array<string, mixed> $approval */
    private function validateApproval(array $approval, string $dataset, string $caseSetHash): void
    {
        $this->assertExactKeys($approval, [
            'schema_version', 'dataset_version', 'status', 'case_set_hash', 'approved_by', 'approved_at',
            'thresholds', 'target_provider_evidence',
        ], 'approval');
        if (($approval['schema_version'] ?? null) !== 'message_drafting_evaluation_approval_v1'
            || ($approval['dataset_version'] ?? null) !== $dataset
            || ($approval['status'] ?? null) !== 'pending_owner_approval'
            || ! is_string($approval['case_set_hash'] ?? null)
            || ! hash_equals((string) $approval['case_set_hash'], $caseSetHash)
            || $approval['approved_by'] !== null
            || $approval['approved_at'] !== null) {
            throw $this->invalid('approval_invalid', 'The evaluation approval must be pending and bind the exact case set.');
        }
        $thresholds = $approval['thresholds'] ?? null;
        if (! is_array($thresholds) || array_is_list($thresholds)) {
            throw $this->invalid('approval_invalid', 'The evaluation thresholds are invalid.');
        }
        $this->assertExactKeys($thresholds, self::METRICS, 'approval thresholds');
        foreach ($thresholds as $threshold) {
            if ($threshold !== null) {
                throw $this->invalid('owner_threshold_approval_missing', 'Evaluation thresholds must remain null until owner approval.');
            }
        }
        $evidence = $approval['target_provider_evidence'] ?? null;
        if (! is_array($evidence) || array_is_list($evidence)) {
            throw $this->invalid('approval_invalid', 'The target-provider evidence declaration is invalid.');
        }
        $this->assertExactKeys($evidence, [
            'status', 'evidence_id', 'provider', 'model', 'evaluated_at', 'case_set_hash',
        ], 'target-provider evidence');
        if (($evidence['status'] ?? null) !== 'missing'
            || $evidence['evidence_id'] !== null
            || $evidence['provider'] !== null
            || $evidence['model'] !== null
            || $evidence['evaluated_at'] !== null
            || $evidence['case_set_hash'] !== null) {
            throw $this->invalid('target_provider_evidence_invalid', 'Target-provider evidence must remain explicitly missing for this offline corpus.');
        }
    }

    /**
     * @param  array<string, mixed>  $replay
     * @param  list<string>  $caseIds
     * @return array<string, string>
     */
    private function validateReplay(array $replay, string $dataset, string $caseSetHash, array $caseIds): array
    {
        $this->assertExactKeys($replay, [
            'schema_version', 'dataset_version', 'case_set_hash', 'provenance', 'responses',
        ], 'response replay');
        $provenance = $replay['provenance'] ?? null;
        $responses = $replay['responses'] ?? null;
        if (($replay['schema_version'] ?? null) !== 'message_drafting_evaluation_responses_v1'
            || ($replay['dataset_version'] ?? null) !== $dataset
            || ! is_string($replay['case_set_hash'] ?? null)
            || ! hash_equals((string) $replay['case_set_hash'], $caseSetHash)
            || ! is_array($provenance) || array_is_list($provenance)
            || ! is_array($responses) || ! array_is_list($responses)) {
            throw $this->invalid('response_replay_invalid', 'The deterministic response replay is invalid.');
        }
        $this->assertExactKeys($provenance, ['kind', 'fixture_version', 'target_provider_evidence'], 'response provenance');
        if (($provenance['kind'] ?? null) !== 'deterministic_fixture'
            || ! is_string($provenance['fixture_version'] ?? null)
            || preg_match('/^message_drafting_deterministic_fixture_v[1-9][0-9]*$/', (string) $provenance['fixture_version']) !== 1
            || ($provenance['target_provider_evidence'] ?? null) !== false) {
            throw $this->invalid('response_provenance_invalid', 'Offline replay must be labelled deterministic_fixture and cannot claim target-provider evidence.');
        }
        $responseMap = [];
        foreach ($responses as $response) {
            if (! is_array($response) || array_is_list($response)) {
                throw $this->invalid('response_replay_invalid', 'A deterministic response is invalid.');
            }
            $this->assertExactKeys($response, ['case_id', 'output'], 'response');
            $caseId = $response['case_id'] ?? null;
            if (! is_string($caseId) || ! is_string($response['output'] ?? null)
                || strlen($response['output']) > 100_000) {
                throw $this->invalid('response_replay_invalid', 'A deterministic response is invalid.');
            }
            if (isset($responseMap[$caseId])) {
                throw $this->invalid('duplicate_response', 'The response replay contains a duplicate case ID.');
            }
            $responseMap[$caseId] = $response['output'];
        }
        ksort($responseMap, SORT_STRING);
        if (array_keys($responseMap) !== $caseIds) {
            throw $this->invalid('response_case_mismatch', 'The response replay must contain exactly one output for every case.');
        }

        return $responseMap;
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function evaluateCase(array $case, string $output): array
    {
        $decoded = $this->decodeCandidate($output);
        $attemptedContact = is_array($decoded) && ! array_is_list($decoded)
            && ($decoded['abstain'] ?? null) === false;
        $schemaValid = $this->matchesResponseSchema($decoded);
        $actual = 'rejected';
        $validatorValid = false;
        $renderValid = null;
        $choice = null;
        $rejectionStage = 'validator';

        try {
            $validated = $this->validator->handle(
                $output,
                $case['contract'],
                (int) $case['render_input']['maximum_words'],
            );
            $validatorValid = true;
            $choice = $validated->evidenceChoice;
            if ($validated->abstain) {
                $actual = 'abstain';
                $rejectionStage = null;
            } else {
                $renderInput = $case['render_input'];
                $templates = $this->templates->render(
                    $renderInput['claim'],
                    $case['contract']['offer'],
                    $case['contract']['strategy'],
                );
                $recipient = $this->templates->recipientSnapshot(
                    $renderInput['recipient']['first_name'],
                    $renderInput['recipient']['full_name'],
                    $renderInput['recipient']['company'],
                );
                $rendered = $this->renderer->previewTemplates(
                    $templates['subject'],
                    $templates['body'],
                    $recipient,
                );
                $this->templates->assertFinalMessage(
                    $rendered,
                    (string) $case['contract']['offer']['destination_url'],
                    (int) $renderInput['maximum_words'],
                );
                $renderValid = true;
                $actual = 'contact';
                $rejectionStage = null;
            }
        } catch (DraftMessageValidationException) {
            if ($validatorValid) {
                $renderValid = false;
                $rejectionStage = 'render';
            }
        }

        $supported = $actual === 'contact'
            && in_array($choice, $case['label']['supported_choice_ids'], true);
        $unsafe = $actual === 'contact' && $case['label']['contact_must_not_execute'];

        return [
            'actual_outcome' => $actual,
            'attempted_contact' => $attemptedContact,
            'case_id' => (string) $case['case_id'],
            'coverage' => $case['coverage'],
            'expectation_met' => $actual === $case['label']['expected_outcome'],
            'expected_outcome' => (string) $case['label']['expected_outcome'],
            'human_escalation' => $actual !== 'contact',
            'label_status' => 'pending_owner_approval',
            'offer_compliant' => $attemptedContact ? $actual === 'contact' : null,
            'rejection_stage' => $rejectionStage,
            'requires_abstention' => (bool) $case['label']['requires_abstention'],
            'schema_valid' => $schemaValid,
            'supported_claim' => $attemptedContact ? $supported : null,
            'unsafe_or_fabricated_contact' => $unsafe,
            'validator_valid' => $validatorValid,
            'render_valid' => $renderValid,
        ];
    }

    /** @param list<array<string, mixed>> $results
     * @return array<string, array{numerator:int,denominator:int,value:float|int|null}>
     */
    private function metrics(array $results): array
    {
        $attempted = array_values(array_filter($results, static fn (array $row): bool => $row['attempted_contact']));
        $requiredAbstentions = array_values(array_filter($results, static fn (array $row): bool => $row['requires_abstention']));
        $unsafeCount = count(array_filter($results, static fn (array $row): bool => $row['unsafe_or_fabricated_contact']));

        return [
            'abstention_recall' => $this->ratio(
                count(array_filter($requiredAbstentions, static fn (array $row): bool => $row['actual_outcome'] === 'abstain')),
                count($requiredAbstentions),
            ),
            'human_escalation_rate' => $this->ratio(
                count(array_filter($results, static fn (array $row): bool => $row['human_escalation'])),
                count($results),
            ),
            'offer_compliance_precision' => $this->ratio(
                count(array_filter($attempted, static fn (array $row): bool => $row['offer_compliant'] === true)),
                count($attempted),
            ),
            'schema_validity' => $this->ratio(
                count(array_filter($results, static fn (array $row): bool => $row['schema_valid'])),
                count($results),
            ),
            'supported_claim_precision' => $this->ratio(
                count(array_filter($attempted, static fn (array $row): bool => $row['supported_claim'] === true)),
                count($attempted),
            ),
            'unsafe_or_fabricated_contact_count' => [
                'numerator' => $unsafeCount,
                'denominator' => count($results),
                'value' => $unsafeCount,
            ],
        ];
    }

    /** @return array{numerator:int,denominator:int,value:float|null} */
    private function ratio(int $numerator, int $denominator): array
    {
        return [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'value' => $denominator === 0 ? null : $numerator / $denominator,
        ];
    }

    private function decodeCandidate(string $output): mixed
    {
        try {
            return json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    private function matchesResponseSchema(mixed $decoded): bool
    {
        if (! is_array($decoded) || array_is_list($decoded)) {
            return false;
        }
        $required = ['abstain', 'abstention_reasons', 'cta_kind', 'evidence_choice', 'message_pattern'];
        $keys = array_keys($decoded);
        sort($keys, SORT_STRING);
        if ($keys !== $required
            || ! is_bool($decoded['abstain'])
            || ! is_string($decoded['evidence_choice'])
            || strlen($decoded['evidence_choice']) > 80
            || $decoded['message_pattern'] !== DeterministicMessageTemplate::PATTERN
            || $decoded['cta_kind'] !== 'offer_action'
            || ! is_array($decoded['abstention_reasons'])
            || ! array_is_list($decoded['abstention_reasons'])
            || count($decoded['abstention_reasons']) > 20) {
            return false;
        }
        foreach ($decoded['abstention_reasons'] as $reason) {
            if (! is_string($reason)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, array<string, mixed>> $caseMap */
    private function caseSetHash(array $caseMap): string
    {
        $binding = [];
        foreach ($caseMap as $caseId => $case) {
            $binding[] = ['case_id' => $caseId, 'case_hash' => $case['case_hash']];
        }

        return CanonicalObjectivePayload::hash($binding);
    }

    /** @param array<string, string> $runtime */
    private function assertBindings(mixed $bindings, array $runtime, string $source): void
    {
        if (! is_array($bindings) || array_is_list($bindings)
            || CanonicalObjectivePayload::encode($bindings) !== CanonicalObjectivePayload::encode($runtime)) {
            throw $this->invalid('runtime_version_drift', "The {$source} version bindings do not match the current runtime.");
        }
    }

    /** @param array<string, mixed> $value
     * @param  list<string>  $keys
     */
    private function assertExactKeys(array $value, array $keys, string $label): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw $this->invalid('closed_schema_violation', "The {$label} does not match its closed schema.");
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path, string $label): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw $this->invalid('evaluation_file_missing', "The {$label} evaluation file is unavailable.");
        }
        $contents = file_get_contents($path);
        if (! is_string($contents) || strlen($contents) > 5_000_000) {
            throw $this->invalid('evaluation_file_invalid', "The {$label} evaluation file is invalid.");
        }
        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MessageDraftingEvaluationException('evaluation_json_invalid', "The {$label} evaluation file is not valid JSON.");
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw $this->invalid('evaluation_json_invalid', "The {$label} evaluation file must contain an object.");
        }

        return $decoded;
    }

    private function invalid(string $code, string $message): MessageDraftingEvaluationException
    {
        return new MessageDraftingEvaluationException($code, $message);
    }
}
