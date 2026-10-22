<?php

use App\Domain\Intelligence\Services\S2OperationalStatus;
use App\Models\Account;
use App\Models\AccountResearchAttempt;
use App\Models\AccountResearchDispatch;
use App\Models\AccountResearchRun;
use App\Models\AccountResearchStage;
use App\Models\ConversionEventReceipt;
use App\Models\ExternalOperation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('reports bounded workspace metrics and safe alert values', function (): void {
    config()->set('intelligence.operations.alerts', array_replace(
        (array) config('intelligence.operations.alerts'),
        [
            'stuck_age_seconds' => 60,
            'held_budget_age_seconds' => 60,
            'paid_result_loss_count' => 1,
            'signature_failure_count' => 1,
            'retention_backlog_count' => 1,
            'manual_review_count' => 100,
        ],
    ));

    $at = CarbonImmutable::parse('2026-09-04T12:00:00Z');
    $old = $at->subHour();
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $account = Account::factory()->create(['workspace_id' => $workspace->id]);

    $run = AccountResearchRun::factory()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'state' => 'running',
        'queued_at' => $old,
        'started_at' => $old,
    ]);
    $stage = AccountResearchStage::factory()->create([
        'workspace_id' => $workspace->id,
        'research_run_id' => $run->id,
        'state' => 'paid_result_lost',
        'safe_error_code' => 'paid_result_lost',
        'started_at' => $old,
    ]);
    AccountResearchAttempt::factory()->create([
        'workspace_id' => $workspace->id,
        'research_run_id' => $run->id,
        'stage_id' => $stage->id,
        'state' => 'provider_boundary',
        'safe_error_code' => 'output_invalid',
        'started_at' => $old,
    ]);
    AccountResearchDispatch::factory()->create([
        'workspace_id' => $workspace->id,
        'research_run_id' => $run->id,
        'state' => 'leased',
        'available_at' => $old,
    ]);
    DB::table('account_research_runs')->where('id', $run->id)->update([
        'created_at' => $old,
        'updated_at' => $old,
    ]);
    DB::table('account_research_stages')->where('id', $stage->id)->update([
        'created_at' => $old,
        'updated_at' => $old,
    ]);
    DB::table('account_research_dispatches')->where('research_run_id', $run->id)->update([
        'created_at' => $old,
        'updated_at' => $old,
    ]);

    ExternalOperation::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => 'executing',
        'started_at' => $old,
        'reserved_at' => $old,
        'created_at' => $old,
        'updated_at' => $old,
    ]);

    DB::table('ai_budget_envelopes')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'kind' => 'research',
        'owner_key' => 'workspace',
        'hard_limit_micros' => 100_000,
        'currency' => 'USD',
        'pricing_version' => 1,
        'catalogue_version' => null,
        'held_micros' => 1_000,
        'committed_micros' => 0,
        'state' => 'open',
        'opened_at' => $old,
        'closed_at' => null,
        'created_at' => $old,
        'updated_at' => $old,
    ]);

    $claimId = (string) Str::uuid();
    DB::table('research_claims')->insert([
        'id' => $claimId,
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'research_run_id' => $run->id,
        'supersedes_claim_id' => null,
        'subject_type' => 'account',
        'predicate' => 'internal_fixture_claim',
        'value_type' => 'string',
        'value_json' => json_encode(['value' => 'must not be emitted'], JSON_THROW_ON_ERROR),
        'value_hash' => hash('sha256', 'claim'),
        'status' => 'conflicting',
        'confidence' => 0.5,
        'calibration_band' => 'medium',
        'observed_at' => $old,
        'fresh_until' => $at->addDay(),
        'expires_at' => $at->addDay(),
        'extractor_kind' => 'fixture',
        'extractor_version' => '1',
        'prompt_version' => 'must-not-be-emitted',
        'model_version' => null,
        'policy_version' => null,
        'catalogue_version' => null,
        'safe_for_message' => false,
        'content_hash' => hash('sha256', 'claim-content'),
        'created_at' => $old,
        'updated_at' => $old,
    ]);
    DB::table('research_claim_events')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'claim_id' => $claimId,
        'action' => 'correct',
        'actor_snapshot' => json_encode(['type' => 'system'], JSON_THROW_ON_ERROR),
        'actor_user_id' => null,
        'reason' => null,
        'successor_claim_id' => null,
        'prior_status' => 'conflicting',
        'projected_status' => 'supported',
        'created_at' => $old,
    ]);

    ConversionEventReceipt::factory()->create([
        'workspace_id' => $workspace->id,
        'verification_state' => 'failed',
        'intake_state' => 'rejected',
        'safe_rejection_code' => 'signature_invalid',
        'unmatched_identity_envelope' => ['email' => 'do-not-emit@example.test'],
        'identity_expires_at' => $old,
    ]);

    $foreignOwner = User::factory()->create();
    $foreignWorkspace = $foreignOwner->currentWorkspace();
    $foreignAccount = Account::factory()->create(['workspace_id' => $foreignWorkspace->id]);
    AccountResearchRun::factory()->create([
        'workspace_id' => $foreignWorkspace->id,
        'account_id' => $foreignAccount->id,
        'state' => 'running',
        'started_at' => $old,
    ]);

    $report = app(S2OperationalStatus::class)->report($workspace->id, $at);
    $json = json_encode($report, JSON_THROW_ON_ERROR);

    expect(data_get($report, 'scope.workspace_id'))->toBe($workspace->id)
        ->and(data_get($report, 'controls.pause_new_research'))->toBeFalse()
        ->and($report)->not->toHaveKey('flags')
        ->and(data_get($report, 'metrics.research.runs.count'))->toBe(1)
        ->and(data_get($report, 'metrics.research.runs.stuck_count'))->toBe(1)
        ->and(data_get($report, 'metrics.research.stuck_count'))->toBe(2)
        ->and(data_get($report, 'metrics.research.stages.paid_result_loss.count'))->toBe(1)
        ->and(data_get($report, 'metrics.provider_operations.stuck_count'))->toBe(1)
        ->and(data_get($report, 'metrics.budget.held_micros'))->toBe(1_000)
        ->and(data_get($report, 'metrics.budget.oldest_hold_age_seconds'))->toBe(3_600)
        ->and(data_get($report, 'metrics.claims.conflict_count'))->toBe(1)
        ->and(data_get($report, 'metrics.claims.correction_count'))->toBe(1)
        ->and(data_get($report, 'metrics.conversions.signature_failure_count'))->toBe(1)
        ->and(data_get($report, 'metrics.retention.backlog_count'))->toBe(1)
        ->and(collect($report['alerts'])->pluck('code'))->toContain(
            's2_research_stuck',
            's2_provider_operation_stuck',
            's2_held_budget_age',
            's2_paid_result_loss',
            's2_webhook_signature_failures',
            's2_retention_backlog',
        )
        ->and($json)->not->toContain('do-not-emit@example.test')
        ->and($json)->not->toContain('must-not-be-emitted')
        ->and($json)->not->toContain('https://');
});

it('exposes a clean machine-readable command for an empty workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();

    $exitCode = Artisan::call('odg-s2:status', [
        '--workspace' => (string) $workspace->id,
        '--json' => true,
    ]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(data_get($report, 'alert_count'))->toBe(0)
        ->and(data_get($report, 'scope.workspace_id'))->toBe($workspace->id);
});

it('rejects malformed and unknown workspace options', function (): void {
    $this->artisan('odg-s2:status', ['--workspace' => 'not-a-number'])
        ->assertExitCode(2)
        ->expectsOutputToContain('positive numeric workspace ID');

    $this->artisan('odg-s2:status', ['--workspace' => '999999999'])
        ->assertExitCode(2)
        ->expectsOutputToContain('workspace was not found');
});
