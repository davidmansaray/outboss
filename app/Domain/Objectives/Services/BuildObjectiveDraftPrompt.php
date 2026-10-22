<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Gtm\Enums\FulfilmentMode;
use App\Domain\Gtm\Enums\OfferReadinessState;
use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Services\EvaluateOfferReadiness;
use App\Domain\Gtm\Support\CanonicalOfferPayload;
use App\Domain\Objectives\Exceptions\DraftMessageValidationException;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\DeterministicMessageTemplate;
use App\Domain\Objectives\Support\MessageEvidenceContract;
use App\Models\AudienceSnapshotMember;
use App\Models\GtmExperimentArm;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\ReasonToContactClaim;
use App\Models\ReasonToContactVersion;
use App\Models\StrategyVersion;
use Illuminate\Validation\ValidationException;

/** Build one arm-level prompt without exposing any Account-specific fact. */
final class BuildObjectiveDraftPrompt
{
    public const VERSION = 'objective_message_prompt_v5';

    public const MESSAGE_CONTRACT_VERSION = 'deterministic_message_contract_v3';

    public function __construct(
        private readonly MessageEvidenceContract $messageEvidence,
        private readonly DeterministicMessageTemplate $templates,
    ) {}

    /**
     * @return array{
     *   content:string,
     *   input_hash:string,
     *   choices:list<array<string,mixed>>,
     *   contract:array<string,mixed>
     * }
     */
    public function handle(GtmExperimentArm $arm): array
    {
        $arm = GtmExperimentArm::query()
            ->where('workspace_id', $arm->workspace_id)
            ->whereKey($arm->id)
            ->with(['experiment', 'assignments.members'])
            ->firstOrFail();
        $strategy = StrategyVersion::query()
            ->where('workspace_id', $arm->workspace_id)
            ->whereKey($arm->strategy_version_id)
            ->firstOrFail();
        $strategyContract = $this->templates->strategyContract($strategy);
        $offer = OfferVersion::query()
            ->where('workspace_id', $arm->workspace_id)
            ->whereKey($strategy->offer_version_id)
            ->firstOrFail();
        $offerContract = $this->offerContract($offer, $strategy);

        $memberIds = $arm->assignments->flatMap(
            fn ($assignment) => $assignment->members->pluck('audience_snapshot_member_id'),
        )->filter()->unique()->values();
        $members = AudienceSnapshotMember::query()
            ->where('workspace_id', $arm->workspace_id)
            ->whereIn('id', $memberIds)
            ->orderBy('id')
            ->get();
        if ($members->isEmpty() || $members->count() !== $memberIds->count()) {
            throw ValidationException::withMessages([
                'evidence' => 'Every assigned member must have frozen evidence before an arm can be drafted.',
            ]);
        }

        $allowedClaimIds = array_values(array_unique(array_map('strval', $strategy->proof_claim_ids)));
        $coverage = [];
        $commonChoiceIds = null;
        $choiceTypes = [];
        foreach ($members as $member) {
            if ($member->account_id === null) {
                throw ValidationException::withMessages([
                    'evidence' => 'Every assigned member must retain its frozen Account scope.',
                ]);
            }
            $linkedClaimIds = $this->linkedClaimIds($member, (int) $arm->workspace_id);
            if ($linkedClaimIds === []) {
                throw ValidationException::withMessages([
                    'evidence' => 'An assigned member has no Strategy proof Claim linked to its exact current safe Reason to Contact.',
                ]);
            }
            $memberChoices = [];
            foreach ($member->evidence_manifest as $frozenClaim) {
                $claimId = (string) ($frozenClaim['claim_id'] ?? '');
                if (! in_array($claimId, $allowedClaimIds, true)
                    || ! in_array($claimId, $linkedClaimIds, true)) {
                    continue;
                }
                $verified = $this->messageEvidence->verifyFrozen(
                    $frozenClaim,
                    (int) $arm->workspace_id,
                    (int) $member->account_id,
                );
                if ($verified === null) {
                    throw ValidationException::withMessages([
                        'evidence' => 'A frozen Claim is no longer current, safe, supported, or verifiable.',
                    ]);
                }
                $choiceId = $this->messageEvidence->choiceId($verified);
                if ($choiceId === null) {
                    continue;
                }
                try {
                    $this->templates->render($verified, $offerContract, $strategyContract);
                } catch (DraftMessageValidationException) {
                    continue;
                }
                $choiceTypes[$choiceId] = (string) $verified['value_type'];
                $memberChoices[$choiceId][] = [
                    'claim_id' => (string) $verified['claim_id'],
                    'safety_hash' => (string) $verified['safety_hash'],
                ];
            }
            if ($memberChoices === []) {
                throw ValidationException::withMessages([
                    'evidence' => 'An assigned member has no exact safe frozen Strategy proof Claim.',
                ]);
            }
            ksort($memberChoices, SORT_STRING);
            foreach ($memberChoices as &$claims) {
                usort($claims, static fn (array $left, array $right): int => strcmp($left['claim_id'], $right['claim_id']));
            }
            unset($claims);
            $coverage[(string) $member->id] = $memberChoices;
            $memberChoiceIds = array_keys($memberChoices);
            $commonChoiceIds = $commonChoiceIds === null
                ? $memberChoiceIds
                : array_values(array_intersect($commonChoiceIds, $memberChoiceIds));
        }
        sort($commonChoiceIds, SORT_STRING);
        if ($commonChoiceIds === []) {
            throw ValidationException::withMessages([
                'evidence' => 'This arm has no safe evidence choice shared by every assigned member.',
            ]);
        }

        $choices = [];
        foreach ($commonChoiceIds as $choiceId) {
            $choiceCoverage = [];
            foreach ($coverage as $memberId => $memberChoices) {
                $choiceCoverage[] = [
                    'member_id' => $memberId,
                    'claims' => $memberChoices[$choiceId],
                ];
            }
            $choices[] = [
                'choice_id' => $choiceId,
                'value_type' => $choiceTypes[$choiceId],
                'coverage_hash' => CanonicalObjectivePayload::hash($choiceCoverage),
            ];
        }

        $internalContract = [
            'contract_version' => self::MESSAGE_CONTRACT_VERSION,
            'template_version' => DeterministicMessageTemplate::VERSION,
            'message_pattern' => DeterministicMessageTemplate::PATTERN,
            'workspace_id' => (int) $arm->workspace_id,
            'strategy_version_id' => (string) $strategy->id,
            'strategy' => $strategyContract,
            'offer' => $offerContract,
            'evidence_choices' => $choices,
        ];
        $providerChoices = array_map(static fn (array $choice): array => [
            'choice_id' => (string) $choice['choice_id'],
            'value_type' => (string) $choice['value_type'],
        ], $choices);
        $payload = [
            'contract' => [
                'prompt_version' => self::VERSION,
                'message_contract_version' => self::MESSAGE_CONTRACT_VERSION,
                'template_version' => DeterministicMessageTemplate::VERSION,
                'response_schema_version' => (string) config('ai.tasks.message_drafting.response_schema.version'),
                'instruction' => 'Select one non-identifying evidence choice and the fixed message pattern, or abstain. The application will separately verify and render each recipient Account fact. Do not write message prose.',
                'channel' => 'email',
                'action_kind' => 'one_new_thread',
                'message_pattern' => DeterministicMessageTemplate::PATTERN,
                'allowed_placeholders' => ['company', 'first_name'],
                'cta_kind' => 'offer_action',
                'abstain_when_support_is_inadequate' => true,
                'free_form_prose_allowed' => false,
                'account_specific_data_included' => false,
            ],
            'offer' => $offerContract,
            'evidence_choices' => $providerChoices,
        ];
        $content = CanonicalObjectivePayload::encode($payload);
        $maximumBytes = max(1, (int) config('ai.tasks.message_drafting.maximum_input_bytes', 60_000));
        if (strlen($content) > $maximumBytes) {
            throw ValidationException::withMessages([
                'prompt' => 'The message drafting input exceeds its bounded policy.',
            ]);
        }

        return [
            'content' => $content,
            'input_hash' => CanonicalObjectivePayload::hash([
                'provider_payload' => $payload,
                'internal_contract' => $internalContract,
            ]),
            'choices' => $choices,
            'contract' => $internalContract,
        ];
    }

    /** @return list<string> */
    private function linkedClaimIds(AudienceSnapshotMember $member, int $workspaceId): array
    {
        if ($member->account_id === null
            || $member->qualification_decision_id === null
            || $member->reason_to_contact_version_id === null
            || ! is_string($member->reason_content_hash)
            || $member->reason_content_hash === '') {
            return [];
        }

        $reason = ReasonToContactVersion::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($member->reason_to_contact_version_id)
            ->where('account_id', $member->account_id)
            ->where('qualification_decision_id', $member->qualification_decision_id)
            ->where('content_hash', $member->reason_content_hash)
            ->whereNull('successor_id')
            ->where('safe_for_message', true)
            ->where('sensitive_inference', false)
            ->where('prohibited_inference', false)
            ->where('fresh_until', '>', now())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
        if (! $reason instanceof ReasonToContactVersion) {
            return [];
        }

        return array_values(ReasonToContactClaim::query()
            ->where('workspace_id', $workspaceId)
            ->where('reason_to_contact_version_id', $reason->id)
            ->pluck('claim_id')
            ->map(static fn (mixed $claimId): string => (string) $claimId)
            ->unique()
            ->sort()
            ->values()
            ->all());
    }

    /** @return array<string, mixed> */
    public function offerContract(OfferVersion $offerVersion, ?StrategyVersion $strategy = null, bool $recheckReadiness = false): array
    {
        $offer = Offer::query()
            ->where('workspace_id', $offerVersion->workspace_id)
            ->whereKey($offerVersion->offer_id)
            ->with('currentReadinessEvaluation')
            ->firstOrFail();
        $readiness = $offer->currentReadinessEvaluation;
        // A fresh deterministic assessment does not approve the Offer: it still
        // requires the owner's existing review of this exact Offer version.
        [$readinessState, , , $validUntil] = $recheckReadiness
            ? app(EvaluateOfferReadiness::class)->assess($offer, $offerVersion)
            : [$readiness?->state, [], [], $readiness?->valid_until];
        if ($validUntil !== null && ! $validUntil->isFuture()) {
            throw ValidationException::withMessages([
                'offer' => 'This Offer’s readiness check has expired. Open the Offer, run Check readiness, then return to this review.',
            ]);
        }
        $payload = $offerVersion->payload;
        $name = $payload['name'] ?? null;
        $commitment = $payload['requested_commitment'] ?? null;
        $action = $payload['cta']['action'] ?? null;
        $destination = $payload['cta']['destination_url'] ?? null;
        $responseRoute = $payload['cta']['response_route'] ?? null;
        $fulfilmentMode = $payload['fulfilment']['mode'] ?? null;
        if (($payload['schema_version'] ?? null) !== 2
            || $offer->status !== OfferStatus::Active
            || (! $recheckReadiness && $offer->availability_state !== OfferReadinessState::Approved)
            || (string) $offer->current_version_id !== (string) $offerVersion->id
            || $readinessState !== OfferReadinessState::Approved
            || (! $recheckReadiness && (string) $readiness?->offer_version_id !== (string) $offerVersion->id)
            || ! hash_equals(
                (string) $offerVersion->content_hash,
                CanonicalOfferPayload::hash($payload, $offerVersion->status_snapshot),
            )
            || ! is_string($name) || trim($name) === ''
            || ! is_string($commitment) || trim($commitment) === ''
            || ! is_string($action) || trim($action) === ''
            || ($strategy !== null && ! hash_equals($action, (string) $strategy->cta_kind))
            || $fulfilmentMode !== FulfilmentMode::ExternalDestination->value
            || ! is_string($destination) || ! $this->safeDestination($destination)
            || ! is_string($responseRoute)
            || (($strategy !== null || $responseRoute !== '') && ! $this->safePlainText($responseRoute, 160))
            || ! $this->safePlainText($name, 160)
            || ! $this->safePlainText($commitment, 500)
            || ! $this->safePlainText($action, 80)) {
            throw ValidationException::withMessages([
                'offer' => 'The exact approved Offer destination cannot be rendered through the safe deterministic message contract.',
            ]);
        }

        $contract = [
            'offer_version_id' => (string) $offerVersion->id,
            'content_hash' => (string) $offerVersion->content_hash,
            'name' => $name,
            'requested_commitment' => $commitment,
            'cta_action' => $action,
            'fulfilment_mode' => $fulfilmentMode,
            'destination_url' => $destination,
            'response_route' => $responseRoute,
        ];

        return [
            ...$contract,
            'contract_hash' => CanonicalObjectivePayload::hash($contract),
        ];
    }

    private function safePlainText(string $value, int $maximum): bool
    {
        return trim($value) !== ''
            && hash_equals($value, trim($value))
            && mb_strlen($value) <= $maximum
            && ! str_contains($value, "\n")
            && ! str_contains($value, '{{')
            && ! str_contains($value, '}}')
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) !== 1
            && preg_match('/<[^>]+>/s', $value) !== 1
            && preg_match('/https?:\/\//i', $value) !== 1
            && ! $this->messageEvidence->prohibited('offer.approved_text', $value);
    }

    private function safeDestination(string $destination): bool
    {
        return hash_equals($destination, trim($destination))
            && strlen($destination) <= 2048
            && filter_var($destination, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($destination, PHP_URL_SCHEME)) === 'https'
            && parse_url($destination, PHP_URL_USER) === null
            && parse_url($destination, PHP_URL_PASS) === null
            && ! str_contains($destination, "\n")
            && ! str_contains($destination, '{{')
            && ! str_contains($destination, '}}')
            && preg_match('/[\x00-\x1F\x7F]/u', $destination) !== 1;
    }
}
