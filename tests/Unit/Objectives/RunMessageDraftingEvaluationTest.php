<?php

use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Objectives\Exceptions\MessageDraftingEvaluationException;
use App\Domain\Objectives\Services\RunMessageDraftingEvaluation;
use App\Domain\Objectives\Services\ValidateDraftedMessage;
use App\Domain\Objectives\Support\DeterministicMessageTemplate;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function isolatedMessageEvaluation(string $root): RunMessageDraftingEvaluation
{
    return new RunMessageDraftingEvaluation(
        app(ValidateDraftedMessage::class),
        app(DeterministicMessageTemplate::class),
        app(RenderSequenceMessage::class),
        $root,
    );
}

/** @return array{0:string,1:string} */
function copyMessageEvaluationCorpus(): array
{
    $root = storage_path('framework/testing/message-evaluation-'.Str::uuid());
    $version = $root.'/v1';
    File::ensureDirectoryExists($root);
    File::copyDirectory(resource_path('evaluations/message-drafting/v1'), $version);

    return [$root, $version];
}

it('loads complete pending-labelled coverage with exact runtime bindings', function (): void {
    $evaluation = app(RunMessageDraftingEvaluation::class);
    $report = $evaluation->handle();
    $cases = json_decode(
        (string) file_get_contents(resource_path('evaluations/message-drafting/v1/cases.json')),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    $coverage = collect($cases['cases'])->flatMap(fn (array $case): array => $case['coverage'])->unique()->sort()->values();

    expect($report['case_count'])->toBe(22)
        ->and($coverage)->toContain(
            'supported_exact_reason_claim_choice',
            'wrong_choice',
            'unknown_choice',
            'unlinked_choice',
            'invented_prose',
            'unsafe_prose',
            'causal_prose',
            'offer_mismatch',
            'cta_mismatch',
            'destination_mismatch',
            'false_familiarity',
            'prohibited_personalization',
            'placeholder_safety',
            'deterministic_render_safety',
            'insufficient_evidence_abstention',
            'conflicting_evidence_abstention',
            'suppression_abstention',
            'rights_abstention',
            'readiness_abstention',
            'malformed_schema',
            'length_limit',
        )
        ->and(collect($cases['cases'])->pluck('label.status')->unique()->all())
        ->toBe(['pending_owner_approval'])
        ->and(collect($cases['cases'])->pluck('runtime_bindings')->unique()->all())
        ->toBe([$evaluation->runtimeBindings()]);
});

it('rejects runtime drift before replaying candidates', function (): void {
    $original = config('ai.tasks.message_drafting.model_id');
    config()->set('ai.tasks.message_drafting.model_id', 'synthetic/drifted-model');

    try {
        try {
            app(RunMessageDraftingEvaluation::class)->handle();
            $this->fail('Version drift should invalidate the corpus.');
        } catch (MessageDraftingEvaluationException $exception) {
            expect($exception->safeCode)->toBe('runtime_version_drift');
        }
    } finally {
        config()->set('ai.tasks.message_drafting.model_id', $original);
    }
});

it('rejects duplicate case IDs', function (): void {
    [$root, $version] = copyMessageEvaluationCorpus();
    try {
        $path = $version.'/cases.json';
        $cases = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $cases['cases'][] = $cases['cases'][0];
        file_put_contents($path, json_encode($cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            isolatedMessageEvaluation($root)->handle();
            $this->fail('A duplicate case ID should invalidate the corpus.');
        } catch (MessageDraftingEvaluationException $exception) {
            expect($exception->safeCode)->toBe('duplicate_case_id');
        }
    } finally {
        File::deleteDirectory($root);
    }
});

it('produces repeatable hashes sorted results and exact metric arithmetic', function (): void {
    $evaluation = app(RunMessageDraftingEvaluation::class);
    $first = $evaluation->handle();
    $second = $evaluation->handle();

    expect($second)->toBe($first)
        ->and($first['result_hash'])->toMatch('/^[a-f0-9]{64}$/')
        ->and(array_column($first['cases'], 'case_id'))->toBe(collect($first['cases'])->pluck('case_id')->sort()->values()->all())
        ->and($first['metrics']['supported_claim_precision'])->toBe([
            'denominator' => 15,
            'numerator' => 1,
            'value' => 1 / 15,
        ])
        ->and($first['metrics']['offer_compliance_precision'])->toBe([
            'denominator' => 15,
            'numerator' => 1,
            'value' => 1 / 15,
        ])
        ->and($first['metrics']['unsafe_or_fabricated_contact_count'])->toBe([
            'denominator' => 22,
            'numerator' => 0,
            'value' => 0,
        ])
        ->and($first['metrics']['abstention_recall'])->toBe([
            'denominator' => 6,
            'numerator' => 6,
            'value' => 1,
        ])
        ->and($first['metrics']['schema_validity'])->toBe([
            'denominator' => 22,
            'numerator' => 12,
            'value' => 12 / 22,
        ])
        ->and($first['metrics']['human_escalation_rate'])->toBe([
            'denominator' => 22,
            'numerator' => 21,
            'value' => 21 / 22,
        ])
        ->and($first['expectation_failure_count'])->toBe(0);
});

it('reports actual validator abstention and deterministic render outcomes', function (): void {
    $cases = collect(app(RunMessageDraftingEvaluation::class)->handle()['cases'])->keyBy('case_id');

    expect($cases['supported_exact_choice'])->toMatchArray([
        'actual_outcome' => 'contact',
        'validator_valid' => true,
        'render_valid' => true,
        'supported_claim' => true,
    ])->and($cases['unknown_choice'])->toMatchArray([
        'actual_outcome' => 'rejected',
        'validator_valid' => false,
        'rejection_stage' => 'validator',
    ])->and($cases['wrong_reason_choice'])->toMatchArray([
        'actual_outcome' => 'rejected',
        'validator_valid' => false,
        'rejection_stage' => 'validator',
    ])->and($cases['unlinked_reason_choice'])->toMatchArray([
        'actual_outcome' => 'abstain',
        'validator_valid' => true,
        'requires_abstention' => true,
    ])->and($cases['unsafe_placeholder'])->toMatchArray([
        'actual_outcome' => 'rejected',
        'validator_valid' => true,
        'render_valid' => false,
        'rejection_stage' => 'render',
    ])->and($cases['render_length_limit'])->toMatchArray([
        'actual_outcome' => 'rejected',
        'validator_valid' => true,
        'render_valid' => false,
        'rejection_stage' => 'render',
    ])->and($cases['insufficient_evidence_abstain'])->toMatchArray([
        'actual_outcome' => 'abstain',
        'validator_valid' => true,
        'requires_abstention' => true,
    ])->and($cases['malformed_json'])->toMatchArray([
        'actual_outcome' => 'rejected',
        'schema_valid' => false,
        'validator_valid' => false,
    ]);
});

it('uses null for precision metrics with a zero denominator', function (): void {
    [$root, $version] = copyMessageEvaluationCorpus();
    try {
        $path = $version.'/responses/deterministic.json';
        $replay = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $abstention = json_encode([
            'message_pattern' => 'claim_offer_v1',
            'evidence_choice' => '',
            'cta_kind' => 'offer_action',
            'abstain' => true,
            'abstention_reasons' => ['Synthetic offline abstention.'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ($replay['responses'] as &$response) {
            $response['output'] = $abstention;
        }
        unset($response);
        file_put_contents($path, json_encode($replay, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $metrics = isolatedMessageEvaluation($root)->handle()['metrics'];
        expect($metrics['supported_claim_precision'])->toBe([
            'denominator' => 0,
            'numerator' => 0,
            'value' => null,
        ])->and($metrics['offer_compliance_precision'])->toBe([
            'denominator' => 0,
            'numerator' => 0,
            'value' => null,
        ]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('keeps approval thresholds null and blocks both required release gates', function (): void {
    $report = app(RunMessageDraftingEvaluation::class)->handle();

    expect($report['release_gate_status'])->toBe('blocked')
        ->and($report['release_gate_reasons'])->toBe([
            'owner_threshold_approval_missing',
            'target_provider_evidence_missing',
        ])
        ->and(array_values(array_unique($report['approval']['thresholds'])))->toBe([null])
        ->and($report['provenance'])->toBe([
            'fixture_version' => 'message_drafting_deterministic_fixture_v2',
            'kind' => 'deterministic_fixture',
            'target_provider_evidence' => false,
        ]);
});
