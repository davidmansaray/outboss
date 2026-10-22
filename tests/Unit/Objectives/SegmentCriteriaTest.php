<?php

use App\Domain\Objectives\Enums\TruthValue;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\SegmentCriteriaEvaluator;
use App\Domain\Objectives\Support\SegmentCriteriaValidator;
use Illuminate\Validation\ValidationException;

it('evaluates nested segment criteria with three-valued missing-data semantics', function (): void {
    $criteria = app(SegmentCriteriaValidator::class)->validate([
        'all' => [
            ['field' => 'account.domain_present', 'operator' => 'eq', 'value' => true],
            ['any' => [
                ['field' => 'qualification.outcome', 'operator' => 'eq', 'value' => 'contact'],
                ['field' => 'person.job_title', 'operator' => 'contains_normalized', 'value' => 'Revenue'],
            ]],
        ],
    ]);
    $evaluator = app(SegmentCriteriaEvaluator::class);

    $included = $evaluator->evaluate($criteria, [
        'account' => ['domain_present' => true],
        'person' => ['job_title' => 'VP Revenue Operations'],
        'qualification' => ['outcome' => null],
    ]);
    $uncertain = $evaluator->evaluate($criteria, [
        'account' => ['domain_present' => true],
        'person' => ['job_title' => null],
        'qualification' => ['outcome' => null],
    ]);
    $excluded = $evaluator->evaluate($criteria, [
        'account' => ['domain_present' => false],
        'person' => ['job_title' => 'VP Revenue Operations'],
        'qualification' => ['outcome' => 'contact'],
    ]);

    expect($included['result'])->toBe(TruthValue::True)
        ->and($uncertain['result'])->toBe(TruthValue::Unknown)
        ->and($uncertain['missing_fields'])->toContain('qualification.outcome', 'person.job_title')
        ->and($excluded['result'])->toBe(TruthValue::False);
});

it('rejects unknown fields, untyped values, and excessive depth', function (): void {
    $validator = app(SegmentCriteriaValidator::class);

    expect(fn () => $validator->validate(['field' => 'account.raw_sql', 'operator' => 'eq', 'value' => true]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $validator->validate(['field' => 'account.domain_present', 'operator' => 'eq', 'value' => 'true']))
        ->toThrow(ValidationException::class)
        ->and(fn () => $validator->validate(['not' => ['not' => ['not' => ['not' => [
            'field' => 'account.domain_present', 'operator' => 'eq', 'value' => true,
        ]]]]]))
        ->toThrow(ValidationException::class);
});

it('produces stable canonical hashes across key ordering and normalized text', function (): void {
    $first = ['b' => 2, 'a' => ['line' => "Cafe\u{0301}\r\n"]];
    $second = ['a' => ['line' => "Café\n"], 'b' => 2];

    expect(CanonicalObjectivePayload::hash($first))->toBe(CanonicalObjectivePayload::hash($second));
});
