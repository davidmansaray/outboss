<?php

use App\Domain\Mail\Services\DeterministicStratifiedAssignment;

it('caps each cohort at the exact target with deterministic stratified sampling', function () {
    $candidates = [
        ['email' => 'one@google.test', 'recipient_provider' => 'google'],
        ['email' => 'two@google.test', 'recipient_provider' => 'google'],
        ['email' => 'three@google.test', 'recipient_provider' => 'google'],
        ['email' => 'four@google.test', 'recipient_provider' => 'google'],
        ['email' => 'one@microsoft.test', 'recipient_provider' => 'microsoft'],
        ['email' => 'two@microsoft.test', 'recipient_provider' => 'microsoft'],
        ['email' => 'three@microsoft.test', 'recipient_provider' => 'microsoft'],
        ['email' => 'four@microsoft.test', 'recipient_provider' => 'microsoft'],
    ];
    $service = app(DeterministicStratifiedAssignment::class);

    $first = $service->assign('fixed-cap-seed', $candidates, ['a', 'b'], 2);
    $second = $service->assign('fixed-cap-seed', array_reverse($candidates), ['a', 'b'], 2);
    $assigned = collect($first)->filter(fn ($decision): bool => ! $decision->isExcluded());

    expect(array_map(fn ($item): array => $item->toArray(), $first))
        ->toBe(array_map(fn ($item): array => $item->toArray(), $second))
        ->and($assigned)->toHaveCount(4)
        ->and($assigned->where('cohortKey', 'a'))->toHaveCount(2)
        ->and($assigned->where('cohortKey', 'b'))->toHaveCount(2)
        ->and(collect($first)->where('exclusionReason', DeterministicStratifiedAssignment::EXCLUSION_TARGET_CAP_REACHED))
        ->toHaveCount(4);
});

it('assigns the available audience deterministically when it cannot fill the target', function () {
    $service = app(DeterministicStratifiedAssignment::class);
    $decisions = $service->assign('short-audience-seed', [
        ['email' => 'one@example.test', 'recipient_provider' => 'google'],
        ['email' => 'two@example.test', 'recipient_provider' => 'microsoft'],
        ['email' => 'three@example.test', 'recipient_provider' => 'other'],
    ], ['a', 'b'], 3);
    $assigned = collect($decisions)->filter(fn ($decision): bool => ! $decision->isExcluded());

    expect($assigned)->toHaveCount(3)
        ->and($assigned->where('cohortKey', 'a'))->toHaveCount(2)
        ->and($assigned->where('cohortKey', 'b'))->toHaveCount(1)
        ->and(collect($decisions)->where('exclusionReason', DeterministicStratifiedAssignment::EXCLUSION_TARGET_CAP_REACHED))
        ->toHaveCount(0);
});

it('carries round robin balance across multiple odd strata without a cap', function () {
    $candidates = [
        ['email' => 'google-one@example.test', 'recipient_provider' => 'google'],
        ['email' => 'google-two@example.test', 'recipient_provider' => 'google'],
        ['email' => 'google-three@example.test', 'recipient_provider' => 'google'],
        ['email' => 'microsoft-one@example.test', 'recipient_provider' => 'microsoft'],
        ['email' => 'microsoft-two@example.test', 'recipient_provider' => 'microsoft'],
        ['email' => 'microsoft-three@example.test', 'recipient_provider' => 'microsoft'],
    ];
    $service = app(DeterministicStratifiedAssignment::class);

    $first = $service->assign('uncapped-balance-seed', $candidates, ['a', 'b']);
    $second = $service->assign('uncapped-balance-seed', array_reverse($candidates), ['a', 'b']);
    $assigned = collect($first)->filter(fn ($decision): bool => ! $decision->isExcluded());

    expect(array_map(fn ($decision): array => $decision->toArray(), $first))
        ->toBe(array_map(fn ($decision): array => $decision->toArray(), $second))
        ->and($assigned->where('cohortKey', 'a'))->toHaveCount(3)
        ->and($assigned->where('cohortKey', 'b'))->toHaveCount(3);
});
