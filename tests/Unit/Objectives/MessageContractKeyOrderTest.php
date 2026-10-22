<?php

use App\Domain\Objectives\Exceptions\DraftMessageValidationException;
use App\Domain\Objectives\Services\ValidateDraftedMessage;
use App\Domain\Objectives\Support\DeterministicMessageTemplate;

it('accepts reordered JSON object keys without changing the exact message contract', function (): void {
    $cases = json_decode(file_get_contents(resource_path('evaluations/message-drafting/v1/cases.json')), true, 64, JSON_THROW_ON_ERROR);
    $responses = json_decode(file_get_contents(resource_path('evaluations/message-drafting/v1/responses/deterministic.json')), true, 64, JSON_THROW_ON_ERROR);
    $contract = collect($cases['cases'])->firstWhere('case_id', 'supported_exact_choice')['contract'];
    $output = collect($responses['responses'])->firstWhere('case_id', 'supported_exact_choice')['output'];
    $validator = app(ValidateDraftedMessage::class);
    $original = $validator->handle($output, $contract, 120);
    $reordered = array_reverse($contract, true);
    $reordered['strategy'] = array_reverse($contract['strategy'], true);
    $reordered['strategy']['projection'] = array_reverse($contract['strategy']['projection'], true);

    expect($validator->handle($output, $reordered, 120))->toEqual($original)
        ->and(app(DeterministicMessageTemplate::class)->strategyProjectionHash($reordered['strategy']['projection']))
        ->toBe($contract['strategy']['projection_hash']);

    $extra = $reordered;
    $extra['strategy']['projection']['unapproved_field'] = 'not allowed';
    expect(fn () => $validator->handle($output, $extra, 120))->toThrow(DraftMessageValidationException::class);
    $missing = $reordered;
    unset($missing['strategy']['projection']['positioning']);
    expect(fn () => $validator->handle($output, $missing, 120))->toThrow(DraftMessageValidationException::class);
});
