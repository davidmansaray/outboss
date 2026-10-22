<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('emits pure privacy-safe JSON offline and exits one for the pending gate', function (): void {
    Http::preventStrayRequests();
    $queries = [];
    DB::listen(static function () use (&$queries): void {
        $queries[] = true;
    });

    $exit = Artisan::call('odg-s3:evaluate-message-drafting', [
        '--dataset' => 'v1',
        '--responses' => 'deterministic',
        '--json' => true,
    ]);
    $output = trim(Artisan::output());
    $report = json_decode($output, true, 64, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($report['release_gate_status'])->toBe('blocked')
        ->and($report['release_gate_reasons'])->toBe([
            'owner_threshold_approval_missing',
            'target_provider_evidence_missing',
        ])
        ->and($report['expectation_failure_count'])->toBe(0)
        ->and($report['provenance']['kind'])->toBe('deterministic_fixture')
        ->and($report['provenance']['target_provider_evidence'])->toBeFalse()
        ->and($queries)->toBe([])
        ->and($output)->not->toContain(
            'Ari Example',
            'sink@evaluation.invalid',
            'medical condition',
            'previous conversation',
            'body_template',
        );
});

it('prints a safe default summary without candidate content', function (): void {
    Http::preventStrayRequests();

    $exit = Artisan::call('odg-s3:evaluate-message-drafting');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain(
            'Message-drafting evaluation v1: BLOCKED',
            'owner_threshold_approval_missing',
            'target_provider_evidence_missing',
            'deterministic_fixture',
        )
        ->not->toContain('Synthetic account revenue doubled');
});

it('exits two with JSON for an invalid corpus or invocation', function (array $options, string $code): void {
    Http::preventStrayRequests();

    $exit = Artisan::call('odg-s3:evaluate-message-drafting', [
        ...$options,
        '--json' => true,
    ]);
    $report = json_decode(trim(Artisan::output()), true, 64, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(2)
        ->and($report)->toBe([
            'error' => [
                'code' => $code,
                'message' => $report['error']['message'],
            ],
            'release_gate_status' => 'invalid',
        ]);
})->with([
    'unknown dataset' => [['--dataset' => 'v999'], 'evaluation_file_missing'],
    'non-version dataset' => [['--dataset' => '../v1'], 'dataset_invalid'],
    'non-offline responses' => [['--responses' => 'live'], 'response_source_invalid'],
]);
