<?php

use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Objectives\Exceptions\DraftMessageValidationException;
use App\Domain\Objectives\Services\BuildObjectiveDraftPrompt;
use App\Domain\Objectives\Services\ValidateDraftedMessage;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\DeterministicMessageTemplate;
use App\Models\StrategyVersion;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $offerChanges
 * @return array<string, mixed>
 */
function choiceMessageContract(
    string $choiceId,
    array $offerChanges = [],
    bool $refreshHash = false,
    int $maximumWords = 250,
    array $strategyChanges = [],
): array {
    $offer = [
        'offer_version_id' => (string) Str::uuid(),
        'content_hash' => str_repeat('c', 64),
        'name' => 'Evidence workshop',
        'requested_commitment' => 'Book one 30-minute workshop.',
        'cta_action' => 'book_workshop',
        'fulfilment_mode' => 'external_destination',
        'destination_url' => 'https://example.test/workshop',
        'response_route' => 'calendar',
    ];
    $offer['contract_hash'] = CanonicalObjectivePayload::hash($offer);
    $offer = [...$offer, ...$offerChanges];
    if ($refreshHash) {
        unset($offer['contract_hash']);
        $offer['contract_hash'] = CanonicalObjectivePayload::hash($offer);
    }

    $strategy = new StrategyVersion([
        'positioning' => 'Evidence-backed planning for revenue leaders.',
        'problem_frame' => 'Account research is difficult to verify.',
        'proof_summary' => 'The frozen Claim supports this message.',
        'cta_kind' => 'book_workshop',
        'cta_text' => 'Book the workshop',
        'proof_claim_ids' => [(string) Str::uuid()],
        'personalisation_contract' => ['allowed_placeholders' => ['company', 'first_name']],
        'message_generation_contract' => ['message_count' => 1, 'maximum_words' => $maximumWords],
        'stop_conditions' => ['on_reply' => true, 'on_conversion' => true],
        'channel' => 'email',
        'sequence_contract' => 'one_new_thread_v1',
        'schema_version' => 'strategy_v1',
        ...$strategyChanges,
    ]);

    return [
        'contract_version' => BuildObjectiveDraftPrompt::MESSAGE_CONTRACT_VERSION,
        'template_version' => DeterministicMessageTemplate::VERSION,
        'message_pattern' => 'claim_offer_v1',
        'workspace_id' => 7,
        'strategy_version_id' => (string) Str::uuid(),
        'strategy' => app(DeterministicMessageTemplate::class)->strategyContract($strategy),
        'offer' => $offer,
        'evidence_choices' => [[
            'choice_id' => $choiceId,
            'value_type' => 'string',
            'coverage_hash' => str_repeat('d', 64),
        ]],
    ];
}

/** @return array<string, mixed> */
function evidenceChoiceSelector(string $choiceId): array
{
    return [
        'message_pattern' => 'claim_offer_v1',
        'evidence_choice' => $choiceId,
        'cta_kind' => 'offer_action',
        'abstain' => false,
        'abstention_reasons' => [],
    ];
}

it('accepts only a non-identifying evidence choice without rendering Account copy', function (): void {
    $choiceId = 'evidence_v1_'.str_repeat('a', 64);
    $draft = app(ValidateDraftedMessage::class)->handle(
        json_encode(evidenceChoiceSelector($choiceId), JSON_THROW_ON_ERROR),
        choiceMessageContract($choiceId, maximumWords: 100),
        100,
    );

    expect($draft->evidenceChoice)->toBe($choiceId)
        ->and($draft->messagePattern)->toBe('claim_offer_v1')
        ->and($draft->abstain)->toBeFalse();
});

it('renders exact Claim Strategy Offer and approved destination only at the deterministic boundary', function (): void {
    $contract = choiceMessageContract('evidence_v1_'.str_repeat('a', 64), maximumWords: 100);
    $rendered = app(DeterministicMessageTemplate::class)->render([
        'predicate' => 'company.category',
        'message_value' => 'B2B software',
    ], $contract['offer'], $contract['strategy']);

    expect($rendered['subject'])->toBe('A practical idea for {{company}}')
        ->and($rendered['body'])->toContain(
            '{{first_name}}',
            '{{company}}',
            'company.category: B2B software',
            'Evidence-backed planning for revenue leaders.',
            'Account research is difficult to verify.',
            'The frozen Claim supports this message.',
            'book_workshop',
            'Book the workshop',
            'Evidence workshop',
            'Book one 30-minute workshop.',
            'https://example.test/workshop',
        );
});

it('makes each declared Strategy treatment change exact template and final content bytes', function (array $strategyChanges, string $expected): void {
    $choiceId = 'evidence_v1_'.str_repeat('a', 64);
    $control = choiceMessageContract($choiceId, maximumWords: 100);
    $treatment = choiceMessageContract(
        $choiceId,
        maximumWords: 100,
        strategyChanges: $strategyChanges,
    );
    $templates = app(DeterministicMessageTemplate::class);
    $claim = ['predicate' => 'company.category', 'message_value' => 'B2B software'];
    $controlTemplate = $templates->render($claim, $control['offer'], $control['strategy']);
    $treatmentTemplate = $templates->render($claim, $treatment['offer'], $treatment['strategy']);
    $snapshot = ['first_name' => 'Maya', 'company' => 'Acme'];
    $controlRendered = app(RenderSequenceMessage::class)->previewTemplates(
        $controlTemplate['subject'],
        $controlTemplate['body'],
        $snapshot,
    );
    $treatmentRendered = app(RenderSequenceMessage::class)->previewTemplates(
        $treatmentTemplate['subject'],
        $treatmentTemplate['body'],
        $snapshot,
    );

    $templates->assertFinalMessage($controlRendered, $control['offer']['destination_url'], 100);
    $templates->assertFinalMessage($treatmentRendered, $treatment['offer']['destination_url'], 100);
    expect($control['strategy']['projection_hash'])->not->toBe($treatment['strategy']['projection_hash'])
        ->and(CanonicalObjectivePayload::hash($controlTemplate))->not->toBe(CanonicalObjectivePayload::hash($treatmentTemplate))
        ->and(CanonicalObjectivePayload::hash($controlRendered))->not->toBe(CanonicalObjectivePayload::hash($treatmentRendered))
        ->and($treatmentRendered['body'])->toContain($expected)
        ->and(substr_count($treatmentRendered['body'], 'https://example.test/workshop'))->toBe(1);
})->with([
    'positioning' => [['positioning' => 'Operational clarity for evidence-led revenue teams.'], 'Operational clarity for evidence-led revenue teams.'],
    'proof' => [['proof_summary' => 'Verified frozen evidence is the primary support.'], 'Verified frozen evidence is the primary support.'],
    'CTA' => [['cta_text' => 'Reserve the approved workshop'], 'Reserve the approved workshop'],
    'copy via problem frame' => [['problem_frame' => 'Reviewed research is difficult to activate safely.'], 'Reviewed research is difficult to activate safely.'],
]);

it('rejects a changed frozen Strategy projection or projection hash', function (string $condition): void {
    $choiceId = 'evidence_v1_'.str_repeat('a', 64);
    $contract = choiceMessageContract($choiceId);
    if ($condition === 'projection') {
        $contract['strategy']['projection']['positioning'] = 'Substituted outbound positioning.';
    } else {
        $contract['strategy']['projection_hash'] = str_repeat('f', 64);
    }

    expect(fn () => app(ValidateDraftedMessage::class)->handle(
        json_encode(evidenceChoiceSelector($choiceId), JSON_THROW_ON_ERROR),
        $contract,
    ))->toThrow(DraftMessageValidationException::class);
})->with(['projection', 'hash']);

it('accepts ordinary governed recipient values and validates their final rendered bytes', function (): void {
    $contract = choiceMessageContract('evidence_v1_'.str_repeat('a', 64), maximumWords: 100);
    $offer = $contract['offer'];
    $templates = app(DeterministicMessageTemplate::class);
    $snapshot = $templates->recipientSnapshot('Maya', 'Maya Jones', 'Stripe & Co');
    $template = $templates->render([
        'predicate' => 'company.category',
        'message_value' => 'B2B software',
    ], $offer, $contract['strategy']);
    $rendered = app(RenderSequenceMessage::class)->previewTemplates(
        $template['subject'],
        $template['body'],
        $snapshot,
    );

    $templates->assertFinalMessage($rendered, $offer['destination_url'], 100);

    expect($snapshot)->toBe(['first_name' => 'Maya', 'company' => 'Stripe & Co'])
        ->and($rendered['subject'])->toBe('A practical idea for Stripe &amp; Co')
        ->and($rendered['body'])->toContain('Hi Maya,', 'https://example.test/workshop');
});

it('rejects unsafe Person and Account snapshot values instead of cleaning them', function (array $values): void {
    expect(fn () => app(DeterministicMessageTemplate::class)->recipientSnapshot(
        $values['first_name'],
        $values['full_name'],
        $values['company'],
    ))->toThrow(DraftMessageValidationException::class);
})->with([
    'CRLF Person first name' => [[
        'first_name' => "Maya\r\nBcc: victim@example.test",
        'full_name' => 'Maya Jones',
        'company' => 'Acme',
    ]],
    'CRLF Account name' => [[
        'first_name' => 'Maya',
        'full_name' => 'Maya Jones',
        'company' => "Acme\r\nSubject: forged",
    ]],
    'header-like Person name' => [[
        'first_name' => 'Reply-To: attacker@example.test',
        'full_name' => null,
        'company' => 'Acme',
    ]],
    'unsafe Account URL' => [[
        'first_name' => 'Maya',
        'full_name' => null,
        'company' => 'https://attacker.example',
    ]],
    'silently trimmed Account name' => [[
        'first_name' => 'Maya',
        'full_name' => null,
        'company' => ' Acme ',
    ]],
]);

it('rejects unsafe or oversized final subject and body bytes', function (string $condition): void {
    $contract = choiceMessageContract('evidence_v1_'.str_repeat('a', 64), maximumWords: 100);
    $offer = $contract['offer'];
    $templates = app(DeterministicMessageTemplate::class);
    $template = $templates->render([
        'predicate' => 'company.category',
        'message_value' => 'B2B software',
    ], $offer, $contract['strategy']);
    $rendered = app(RenderSequenceMessage::class)->previewTemplates(
        $template['subject'],
        $template['body'],
        ['first_name' => 'Maya', 'company' => 'Acme'],
    );
    $unsafe = match ($condition) {
        'crlf_subject' => [...$rendered, 'subject' => $rendered['subject']."\r\nBcc: victim@example.test"],
        'header_body' => [...$rendered, 'body' => "Bcc: victim@example.test\n".$rendered['body']],
        'unapproved_url' => [...$rendered, 'body' => $rendered['body']."\nhttps://attacker.example/collect"],
        'control' => [...$rendered, 'body' => $rendered['body']."\0"],
        'subject_size' => [...$rendered, 'subject' => str_repeat('a', 161)],
        'body_size' => [...$rendered, 'body' => str_repeat('word ', 2500)],
        default => throw new InvalidArgumentException('Unknown rendered-message condition.'),
    };

    expect(fn () => $templates->assertFinalMessage(
        $unsafe,
        $offer['destination_url'],
        100,
    ))->toThrow(DraftMessageValidationException::class);
})->with([
    'CRLF subject injection' => ['crlf_subject'],
    'header-like body line' => ['header_body'],
    'unapproved body URL' => ['unapproved_url'],
    'body control character' => ['control'],
    'oversized subject' => ['subject_size'],
    'oversized body' => ['body_size'],
]);

it('accepts a clean abstention and produces no selected evidence choice', function (): void {
    $choiceId = 'evidence_v1_'.str_repeat('a', 64);
    $output = [
        'message_pattern' => 'claim_offer_v1',
        'evidence_choice' => '',
        'cta_kind' => 'offer_action',
        'abstain' => true,
        'abstention_reasons' => ['The approved evidence is insufficient.'],
    ];

    $draft = app(ValidateDraftedMessage::class)->handle(
        json_encode($output, JSON_THROW_ON_ERROR),
        choiceMessageContract($choiceId),
    );

    expect($draft->abstain)->toBeTrue()
        ->and($draft->evidenceChoice)->toBe('');
});

it('rejects any provider prose even when it includes an allowed evidence choice', function (string $field, string $prose): void {
    $choiceId = 'evidence_v1_'.str_repeat('a', 64);
    $payload = [...evidenceChoiceSelector($choiceId), $field => $prose];

    expect(fn () => app(ValidateDraftedMessage::class)->handle(
        json_encode($payload, JSON_THROW_ON_ERROR),
        choiceMessageContract($choiceId),
    ))->toThrow(DraftMessageValidationException::class);
})->with([
    'fabricated causality' => ['body_template', 'Your hiring caused a revenue decline, so you need this.'],
    'guarantee and capacity promise' => ['body_template', 'We guarantee results and have unlimited capacity.'],
    'competitor assertion' => ['body_template', 'You are falling behind CompetitorCo.'],
    'paraphrased sensitive attribute' => ['body_template', 'Your medical condition suggests this is urgent.'],
    'false familiarity' => ['subject_template', 'Re: our previous conversation'],
]);

it('rejects a tampered stale Offer destination contract', function (): void {
    $choiceId = 'evidence_v1_'.str_repeat('a', 64);

    expect(fn () => app(ValidateDraftedMessage::class)->handle(
        json_encode(evidenceChoiceSelector($choiceId), JSON_THROW_ON_ERROR),
        choiceMessageContract($choiceId, ['destination_url' => 'https://attacker.example/redirect']),
    ))->toThrow(DraftMessageValidationException::class);
});

it('rejects unapproved destination modes and unsafe URLs even with a refreshed contract hash', function (array $changes): void {
    $choiceId = 'evidence_v1_'.str_repeat('a', 64);

    expect(fn () => app(ValidateDraftedMessage::class)->handle(
        json_encode(evidenceChoiceSelector($choiceId), JSON_THROW_ON_ERROR),
        choiceMessageContract($choiceId, $changes, true),
    ))->toThrow(DraftMessageValidationException::class);
})->with([
    'unsupported fulfilment mode' => [['fulfilment_mode' => 'human_assisted']],
    'non HTTPS destination' => [['destination_url' => 'http://example.test/workshop']],
    'credential-bearing destination' => [['destination_url' => 'https://user:secret@example.test/workshop']],
]);

it('rejects unknown choices patterns extra fields and executable abstentions', function (array $changes): void {
    $choiceId = 'evidence_v1_'.str_repeat('a', 64);
    $payload = [...evidenceChoiceSelector($choiceId), ...$changes];

    expect(fn () => app(ValidateDraftedMessage::class)->handle(
        json_encode($payload, JSON_THROW_ON_ERROR),
        choiceMessageContract($choiceId),
    ))->toThrow(DraftMessageValidationException::class);
})->with([
    'unknown choice' => fn (): array => ['evidence_choice' => 'evidence_v1_'.str_repeat('b', 64)],
    'unknown pattern' => fn (): array => ['message_pattern' => 'free_form_v1'],
    'extra provider field' => fn (): array => ['raw_provider_response' => 'must never persist'],
    'executable abstention' => fn (): array => ['abstain' => true, 'abstention_reasons' => ['No support.']],
]);
