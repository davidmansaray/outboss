<?php

namespace App\Domain\Objectives\Support;

use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Objectives\Exceptions\DraftMessageValidationException;
use App\Models\StrategyVersion;
use Illuminate\Support\Str;

/** Render provider selections into exact, reviewable copy without model prose. */
final class DeterministicMessageTemplate
{
    public const PATTERN = 'claim_offer_v1';

    public const VERSION = 'deterministic_message_template_v2';

    public const STRATEGY_PROJECTION_VERSION = 'outbound_strategy_projection_v1';

    /**
     * Every Strategy value that can select, constrain, or enter outbound
     * message bytes. The projection is deliberately closed: adding another
     * output-affecting Strategy field requires a versioned contract change.
     */
    public const STRATEGY_PROJECTION_FIELDS = [
        'projection_version',
        'positioning',
        'problem_frame',
        'proof_summary',
        'cta_kind',
        'cta_text',
        'proof_claim_ids',
        'allowed_placeholders',
        'message_count',
        'maximum_words',
        'stop_on_reply',
        'stop_on_conversion',
        'channel',
        'sequence_contract',
        'strategy_schema_version',
    ];

    public function __construct(private readonly MessageEvidenceContract $messageEvidence) {}

    /**
     * Freeze the exact outbound Strategy representation and its canonical
     * digest. Strategy identity and display name are intentionally excluded:
     * neither is permitted to influence recipient-visible bytes.
     *
     * @return array{projection:array<string,mixed>,projection_hash:string}
     */
    public function strategyContract(StrategyVersion $strategy): array
    {
        $personalisation = $strategy->personalisation_contract;
        $generation = $strategy->message_generation_contract;
        $stops = $strategy->stop_conditions;
        $projection = [
            'projection_version' => self::STRATEGY_PROJECTION_VERSION,
            'positioning' => $strategy->positioning,
            'problem_frame' => $strategy->problem_frame,
            'proof_summary' => $strategy->proof_summary,
            'cta_kind' => $strategy->cta_kind,
            'cta_text' => $strategy->cta_text,
            'proof_claim_ids' => $strategy->proof_claim_ids,
            'allowed_placeholders' => $personalisation['allowed_placeholders'] ?? null,
            'message_count' => $generation['message_count'] ?? null,
            'maximum_words' => $generation['maximum_words'] ?? null,
            'stop_on_reply' => $stops['on_reply'] ?? null,
            'stop_on_conversion' => $stops['on_conversion'] ?? null,
            'channel' => $strategy->channel,
            'sequence_contract' => $strategy->sequence_contract,
            'strategy_schema_version' => $strategy->schema_version,
        ];
        $this->assertStrategyProjection($projection);

        return [
            'projection' => $projection,
            'projection_hash' => $this->strategyProjectionHash($projection),
        ];
    }

    /**
     * @param  array<string, mixed>  $projection
     */
    public function strategyProjectionHash(array $projection): string
    {
        $this->assertStrategyProjection($projection);

        return CanonicalObjectivePayload::hash($projection);
    }

    /**
     * Revalidate a frozen Strategy contract without cleaning or rebuilding it.
     *
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    public function assertStrategyContract(array $contract): array
    {
        if (count($contract) !== 2
            || array_diff(array_keys($contract), ['projection', 'projection_hash']) !== []
            || ! is_array($contract['projection'] ?? null)
            || ! is_string($contract['projection_hash'] ?? null)) {
            throw new DraftMessageValidationException('The frozen outbound Strategy contract is incomplete.');
        }
        $projection = $contract['projection'];
        $this->assertStrategyProjection($projection);
        if (! hash_equals($this->strategyProjectionHash($projection), $contract['projection_hash'])) {
            throw new DraftMessageValidationException('The frozen outbound Strategy projection hash changed.');
        }

        return $projection;
    }

    /**
     * @param  array<string, mixed>  $claim
     * @param  array<string, mixed>  $offer
     * @param  array<string, mixed>  $strategyContract
     * @return array{subject:string,body:string}
     */
    public function render(array $claim, array $offer, array $strategyContract): array
    {
        $strategy = $this->assertStrategyContract($strategyContract);
        $predicate = $this->plain($claim['predicate'] ?? null, 'Claim predicate', 160);
        $value = $this->plain($claim['message_value'] ?? null, 'Claim value', 500);
        $offerName = $this->plain($offer['name'] ?? null, 'Offer name', 160);
        $commitment = $this->plain($offer['requested_commitment'] ?? null, 'Offer commitment', 500);
        $offerAction = $this->plain($offer['cta_action'] ?? null, 'Offer action', 80);
        $responseRoute = $this->plain($offer['response_route'] ?? null, 'Offer response route', 160);
        $destination = $this->destination($offer['destination_url'] ?? null);
        if (! hash_equals((string) $strategy['cta_kind'], $offerAction)) {
            throw new DraftMessageValidationException('Strategy action no longer matches the exact approved Offer action.');
        }

        $subject = 'A practical idea for {{company}}';
        $body = "Hi {{first_name}},\n\nFor {{company}}, {$strategy['positioning']} {$strategy['problem_frame']} Our approved proof framing is: {$strategy['proof_summary']} The reviewed account record contains this exact fact: {$predicate}: {$value}. I’m sharing it as context only, without assuming cause, urgency, or results. The exact approved offer is {$offerName}, and its exact action is {$offerAction}. {$strategy['cta_text']}: {$commitment} Use the approved {$responseRoute} response route: {$destination}";
        $this->assertTemplate($subject, $body, (int) $strategy['maximum_words']);

        return [
            'subject' => $subject,
            'body' => $body,
        ];
    }

    /**
     * Build the exact governed recipient values without cleaning unsafe input.
     * Missing first names retain the ordinary campaign renderer's documented
     * fallback, while present Person and Account data must be safe as stored.
     *
     * @return array{first_name:string,company:string}
     */
    public function recipientSnapshot(?string $firstName, ?string $fullName, string $company): array
    {
        if ($firstName === null || $firstName === '') {
            if ($fullName === null || $fullName === '') {
                $firstName = 'there';
            } else {
                $this->assertRecipientValue($fullName, 'Person name', 255);
                $firstName = explode(' ', $fullName, 2)[0];
            }
        }

        $this->assertRecipientValue($firstName, 'Person first name', 160);
        $this->assertRecipientValue($company, 'Account name', 255);

        return [
            'first_name' => $firstName,
            'company' => $company,
        ];
    }

    /**
     * Validate the final expanded bytes that will be covered by approval.
     * The only URL permitted in the body is the exact approved destination.
     *
     * @param  array{subject:string,body:string}  $rendered
     */
    public function assertFinalMessage(array $rendered, string $approvedDestination, int $maximumWords = 250): void
    {
        $subject = $rendered['subject'];
        $body = $rendered['body'];
        $destination = $this->destination($approvedDestination);

        if ($subject === '' || mb_strlen($subject) > 160 || $this->hasUnsafeControl($subject)
            || $body === '' || mb_strlen($body) > 12_000 || $this->hasUnsafeControl($body, allowLineFeed: true)
            || preg_match('/<[^>]+>/s', $subject."\n".$body) === 1
            || $this->headerLike($subject)
            || $this->headerLike($body, lineAware: true)) {
            throw new DraftMessageValidationException('The rendered message contains unsafe or oversized exact content.');
        }

        $words = preg_split('/\s+/u', strip_tags($body), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 40 || count($words) > min(250, max(40, $maximumWords))) {
            throw new DraftMessageValidationException('The rendered message body is outside the approved 40 to 250 word range.');
        }

        if (preg_match_all(
            '~(?:(?:https?|ftp)://|(?:mailto|data|javascript):|www\.)[^\s<>"\']+~iu',
            $subject."\n".$body,
            $matches,
        ) === false) {
            throw new DraftMessageValidationException('The rendered message contains invalid URL text.');
        }
        foreach ($matches[0] as $url) {
            if (! hash_equals($destination, $url)) {
                throw new DraftMessageValidationException('The rendered message contains an unapproved URL.');
            }
        }
        if (substr_count($body, $destination) !== 1 || str_contains($subject, $destination)) {
            throw new DraftMessageValidationException('The rendered message does not contain exactly one approved destination.');
        }
    }

    private function destination(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 2048
            || ! hash_equals($value, trim($value))
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https'
            || parse_url($value, PHP_URL_USER) !== null
            || parse_url($value, PHP_URL_PASS) !== null
            || $this->hasUnsafeControl($value)
            || str_contains($value, '{{') || str_contains($value, '}}')) {
            throw new DraftMessageValidationException('Offer destination is not a safe approved HTTPS URL.');
        }

        return $value;
    }

    private function assertTemplate(string $subject, string $body, int $maximumWords): void
    {
        if ($subject === '' || mb_strlen($subject) > 160 || $this->hasUnsafeControl($subject)
            || $body === '' || mb_strlen($body) > 12_000 || $this->hasUnsafeControl($body, allowLineFeed: true)) {
            throw new DraftMessageValidationException('The deterministic message is outside its approved size limits.');
        }
        $words = preg_split('/\s+/u', strip_tags($body), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 40 || count($words) > min(250, max(40, $maximumWords))) {
            throw new DraftMessageValidationException('The deterministic message body is outside the approved 40 to 250 word range.');
        }
        foreach ([$subject, $body] as $template) {
            if (preg_match('/\{[^{}]*\|[^{}]*\}/', $template) === 1) {
                throw new DraftMessageValidationException('Spintax is not supported in governed message drafts.');
            }
            if (preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $template, $matches)) {
                foreach ($matches[1] as $variable) {
                    if (! in_array($variable, RenderSequenceMessage::SUPPORTED_VARIABLES, true)) {
                        throw new DraftMessageValidationException('The deterministic message contains an unsupported placeholder.');
                    }
                }
            }
            $withoutVariables = preg_replace('/\{\{\s*\w+\s*\}\}/', '', $template) ?? $template;
            if (str_contains($withoutVariables, '{{') || str_contains($withoutVariables, '}}')) {
                throw new DraftMessageValidationException('The deterministic message contains malformed placeholders.');
            }
        }
    }

    /** @param array<string, mixed> $projection */
    private function assertStrategyProjection(array $projection): void
    {
        // JSON objects are unordered; MySQL normalizes their key order.
        // Require the exact field set without binding approval to storage order.
        if (count($projection) !== count(self::STRATEGY_PROJECTION_FIELDS)
            || array_diff(array_keys($projection), self::STRATEGY_PROJECTION_FIELDS) !== []
            || ($projection['projection_version'] ?? null) !== self::STRATEGY_PROJECTION_VERSION
            || ($projection['allowed_placeholders'] ?? null) !== ['company', 'first_name']
            || ($projection['message_count'] ?? null) !== 1
            || ! is_int($projection['maximum_words'] ?? null)
            || $projection['maximum_words'] < 40
            || $projection['maximum_words'] > 250
            || ($projection['stop_on_reply'] ?? null) !== true
            || ($projection['stop_on_conversion'] ?? null) !== true
            || ($projection['channel'] ?? null) !== 'email'
            || ($projection['sequence_contract'] ?? null) !== 'one_new_thread_v1'
            || ($projection['strategy_schema_version'] ?? null) !== 'strategy_v1') {
            throw new DraftMessageValidationException('The frozen outbound Strategy projection is not supported.');
        }

        $claimIds = $projection['proof_claim_ids'] ?? null;
        if (! is_array($claimIds) || ! array_is_list($claimIds) || $claimIds === []
            || array_filter($claimIds, static fn (mixed $claimId): bool => ! is_string($claimId) || ! Str::isUuid($claimId)) !== []
            || array_values(array_unique($claimIds)) !== $claimIds) {
            throw new DraftMessageValidationException('The frozen outbound Strategy proof set is invalid.');
        }

        $limits = [
            'positioning' => [240, 30],
            'problem_frame' => [240, 30],
            'proof_summary' => [240, 30],
            'cta_kind' => [48, 4],
            'cta_text' => [160, 20],
        ];
        $combined = [];
        foreach ($limits as $field => [$maximumBytes, $maximumWords]) {
            $value = $projection[$field] ?? null;
            if (! is_string($value)) {
                throw new DraftMessageValidationException('The frozen outbound Strategy text is incomplete.');
            }
            $this->plainStrategy($value, $field, $maximumBytes, $maximumWords);
            $combined[] = $value;
        }
        $combinedText = implode(' ', $combined);
        if (strlen($combinedText) > 640 || $this->wordCount($combinedText) > 60) {
            throw new DraftMessageValidationException('The frozen outbound Strategy text exceeds its combined message budget.');
        }
    }

    private function plain(mixed $value, string $label, int $maximum): string
    {
        if (! is_string($value) || $value === '' || ! hash_equals($value, trim($value)) || mb_strlen($value) > $maximum
            || $this->hasUnsafeControl($value) || str_contains($value, '{{') || str_contains($value, '}}')
            || preg_match('~(?:(?:https?|ftp)://|(?:mailto|data|javascript):|www\.)~iu', $value) === 1
            || preg_match('/<[^>]+>/s', $value) === 1) {
            throw new DraftMessageValidationException("{$label} is not safe deterministic message text.");
        }

        return $value;
    }

    private function plainStrategy(string $value, string $field, int $maximumBytes, int $maximumWords): void
    {
        if ($value === '' || ! hash_equals($value, trim($value))
            || preg_match('/^\s|\s$/u', $value) !== 0
            || strlen($value) > $maximumBytes || $this->wordCount($value) > $maximumWords
            || $this->hasUnsafeControl($value) || $this->headerLike($value)
            || str_contains($value, '{') || str_contains($value, '}')
            || preg_match('~(?:(?:https?|ftp)://|(?:mailto|data|javascript):|www\.|\b(?:[a-z0-9-]+\.)+[a-z]{2,63}(?:[/?:#][^\s]*)?|\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})~iu', $value) === 1
            || str_contains($value, '<') || str_contains($value, '>')
            || $this->messageEvidence->prohibited('strategy.'.$field, $value)) {
            throw new DraftMessageValidationException("The frozen outbound Strategy {$field} is unsafe or oversized.");
        }
    }

    private function wordCount(string $value): int
    {
        return count(preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private function assertRecipientValue(string $value, string $label, int $maximum): void
    {
        if ($value === '' || ! hash_equals($value, trim($value)) || mb_strlen($value) > $maximum
            || $this->hasUnsafeControl($value)
            || $this->headerLike($value)
            || str_contains($value, '{{') || str_contains($value, '}}')
            || preg_match('~(?:(?:https?|ftp)://|(?:mailto|data|javascript):|www\.)~iu', $value) === 1
            || preg_match('/<[^>]+>/s', $value) === 1) {
            throw new DraftMessageValidationException("{$label} is not safe governed recipient text.");
        }
    }

    private function hasUnsafeControl(string $value, bool $allowLineFeed = false): bool
    {
        $candidate = $allowLineFeed ? str_replace("\n", '', $value) : $value;

        return preg_match('/\p{Cc}|[\x{0085}\x{2028}\x{2029}]/u', $candidate) !== 0;
    }

    private function headerLike(string $value, bool $lineAware = false): bool
    {
        $prefix = $lineAware ? '(?:^|\n)' : '^';

        return preg_match(
            '/'.$prefix.'[ ]*(?:to|from|cc|bcc|reply-to|sender|return-path|subject|content-type|content-transfer-encoding|mime-version)\s*:/iu',
            $value,
        ) !== 0;
    }
}
