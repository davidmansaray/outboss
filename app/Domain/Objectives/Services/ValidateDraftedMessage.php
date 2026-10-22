<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Gtm\Enums\FulfilmentMode;
use App\Domain\Objectives\DTOs\ValidatedDraftMessage;
use App\Domain\Objectives\Exceptions\DraftMessageValidationException;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\DeterministicMessageTemplate;
use App\Domain\Objectives\Support\MessageEvidenceContract;
use Illuminate\Support\Str;
use JsonException;

/** Validate a selector-only model result; exact Account copy is rendered later. */
final class ValidateDraftedMessage
{
    public const VERSION = 'objective_message_validator_v3';

    public function __construct(
        private readonly MessageEvidenceContract $messageEvidence,
        private readonly DeterministicMessageTemplate $templates,
    ) {}

    /**
     * @param  array<string, mixed>  $frozenContract
     */
    public function handle(string $output, array $frozenContract, int $maximumWords = 250): ValidatedDraftMessage
    {
        $requiredContractKeys = [
            'contract_version', 'template_version', 'message_pattern', 'workspace_id',
            'strategy_version_id', 'strategy', 'offer', 'evidence_choices',
        ];
        if (count($frozenContract) !== count($requiredContractKeys)
            || array_diff(array_keys($frozenContract), $requiredContractKeys) !== []
            || ($frozenContract['contract_version'] ?? null) !== BuildObjectiveDraftPrompt::MESSAGE_CONTRACT_VERSION
            || ($frozenContract['template_version'] ?? null) !== DeterministicMessageTemplate::VERSION
            || (int) ($frozenContract['workspace_id'] ?? 0) < 1
            || ! Str::isUuid((string) ($frozenContract['strategy_version_id'] ?? ''))
            || ! is_array($frozenContract['strategy'] ?? null)) {
            throw new DraftMessageValidationException('The frozen message contract is invalid.');
        }
        $strategy = $this->templates->assertStrategyContract($frozenContract['strategy']);
        if ($maximumWords < 40 || $maximumWords > 250
            || $maximumWords !== $strategy['maximum_words']) {
            throw new DraftMessageValidationException('The frozen message word limit changed.');
        }
        try {
            $decoded = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DraftMessageValidationException('The message draft was not valid JSON.', 0, $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new DraftMessageValidationException('The message draft must be a JSON object.');
        }
        $required = ['message_pattern', 'evidence_choice', 'cta_kind', 'abstain', 'abstention_reasons'];
        if (array_diff(array_keys($decoded), $required) !== [] || array_diff($required, array_keys($decoded)) !== []) {
            throw new DraftMessageValidationException('The message draft does not match the closed selector contract.');
        }
        if (! is_string($decoded['message_pattern'])
            || ! is_string($decoded['evidence_choice'])
            || ! is_string($decoded['cta_kind'])
            || ! is_bool($decoded['abstain'])) {
            throw new DraftMessageValidationException('The message draft contains fields with invalid types.');
        }
        if ($decoded['message_pattern'] !== DeterministicMessageTemplate::PATTERN
            || ($frozenContract['message_pattern'] ?? null) !== DeterministicMessageTemplate::PATTERN) {
            throw new DraftMessageValidationException('The message draft uses an unapproved deterministic pattern.');
        }
        if ($decoded['cta_kind'] !== 'offer_action') {
            throw new DraftMessageValidationException('The message draft uses an unapproved CTA kind.');
        }

        $choiceId = trim($decoded['evidence_choice']);
        if (strlen($choiceId) > 80) {
            throw new DraftMessageValidationException('The message draft selected an invalid evidence choice.');
        }
        $reasons = $this->stringList($decoded['abstention_reasons'], 'abstention_reasons', 20, 500, allowEmpty: true);
        if ($decoded['abstain']) {
            if ($reasons === [] || $choiceId !== '') {
                throw new DraftMessageValidationException('An abstaining draft must give reasons and select no evidence choice.');
            }

            return new ValidatedDraftMessage(
                DeterministicMessageTemplate::PATTERN,
                '',
                'offer_action',
                true,
                $reasons,
            );
        }
        if ($reasons !== [] || $choiceId === '') {
            throw new DraftMessageValidationException('An executable draft must select one approved evidence choice and cannot include abstention reasons.');
        }

        $choice = null;
        $contractChoices = $frozenContract['evidence_choices'] ?? null;
        if (is_array($contractChoices)) {
            foreach ($contractChoices as $candidate) {
                if (is_array($candidate)
                    && hash_equals((string) ($candidate['choice_id'] ?? ''), $choiceId)) {
                    $choice = $candidate;
                    break;
                }
            }
        }
        $offer = $frozenContract['offer'] ?? null;
        if (! is_array($choice) || ! is_array($offer)) {
            throw new DraftMessageValidationException('The selected evidence choice or Offer is outside the frozen message contract.');
        }
        $this->assertChoice($choice);
        $this->assertFrozenOffer($offer);

        return new ValidatedDraftMessage(
            DeterministicMessageTemplate::PATTERN,
            $choiceId,
            'offer_action',
            false,
            [],
        );
    }

    /** @param array<string, mixed> $choice */
    private function assertChoice(array $choice): void
    {
        $required = ['choice_id', 'value_type', 'coverage_hash'];
        if (array_diff($required, array_keys($choice)) !== []
            || array_diff(array_keys($choice), $required) !== []
            || ! is_string($choice['choice_id'] ?? null)
            || ! str_starts_with((string) $choice['choice_id'], 'evidence_v1_')
            || strlen((string) $choice['choice_id']) !== 76
            || ! is_string($choice['coverage_hash'] ?? null)
            || strlen((string) $choice['coverage_hash']) !== 64
            || ! in_array($choice['value_type'] ?? null, ['string', 'date', 'integer', 'number', 'boolean', 'string_list'], true)) {
            throw new DraftMessageValidationException('The selected evidence choice is incomplete.');
        }
    }

    /** @param array<string, mixed> $offer */
    private function assertFrozenOffer(array $offer): void
    {
        $required = [
            'offer_version_id', 'content_hash', 'name', 'requested_commitment', 'cta_action',
            'fulfilment_mode', 'destination_url', 'response_route', 'contract_hash',
        ];
        $contractHash = $offer['contract_hash'] ?? null;
        $contract = $offer;
        unset($contract['contract_hash']);
        $destination = $offer['destination_url'] ?? null;
        if (array_diff($required, array_keys($offer)) !== []
            || array_diff(array_keys($offer), $required) !== []
            || ! Str::isUuid((string) ($offer['offer_version_id'] ?? ''))
            || ! is_string($offer['content_hash'] ?? null)
            || strlen((string) $offer['content_hash']) !== 64
            || ! is_string($contractHash)
            || ! hash_equals($contractHash, CanonicalObjectivePayload::hash($contract))
            || ($offer['fulfilment_mode'] ?? null) !== FulfilmentMode::ExternalDestination->value
            || ! is_string($destination)
            || strlen($destination) > 2048
            || filter_var($destination, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($destination, PHP_URL_SCHEME)) !== 'https'
            || parse_url($destination, PHP_URL_USER) !== null
            || parse_url($destination, PHP_URL_PASS) !== null
            || str_contains($destination, "\n")
            || str_contains($destination, '{{')
            || str_contains($destination, '}}')
            || preg_match('/[\x00-\x1F\x7F]/u', $destination) === 1
            || ! $this->safePlainText($offer['name'] ?? null, 160)
            || ! $this->safePlainText($offer['requested_commitment'] ?? null, 500)
            || ! $this->safePlainText($offer['cta_action'] ?? null, 80)
            || ! $this->safePlainText($offer['response_route'] ?? null, 160)) {
            throw new DraftMessageValidationException('The exact approved Offer destination contract is incomplete.');
        }
    }

    private function safePlainText(mixed $value, int $maximum): bool
    {
        return is_string($value)
            && trim($value) !== ''
            && mb_strlen($value) <= $maximum
            && ! str_contains($value, "\n")
            && ! str_contains($value, '{{')
            && ! str_contains($value, '}}')
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) !== 1
            && preg_match('/<[^>]+>/s', $value) !== 1
            && preg_match('/https?:\/\//i', $value) !== 1
            && ! $this->messageEvidence->prohibited('offer.approved_text', $value);
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $field, int $maximumItems, int $maximumBytes, bool $allowEmpty): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maximumItems) {
            throw new DraftMessageValidationException("The {$field} list is invalid.");
        }
        $result = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '' || strlen($item) > $maximumBytes) {
                throw new DraftMessageValidationException("The {$field} list contains an invalid item.");
            }
            $result[] = trim($item);
        }
        $result = array_values(array_unique($result));
        if (! $allowEmpty && $result === []) {
            throw new DraftMessageValidationException("The {$field} list cannot be empty.");
        }

        return $result;
    }
}
