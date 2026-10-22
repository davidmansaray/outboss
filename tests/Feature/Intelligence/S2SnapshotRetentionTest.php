<?php

use App\Domain\Intelligence\DTOs\StartAccountResearchRequest;
use App\Domain\Intelligence\Enums\QualificationReviewAction;
use App\Domain\Intelligence\Services\EnsureQualificationRubric;
use App\Domain\Intelligence\Services\EvaluateAccountQualification;
use App\Domain\Intelligence\Services\ExpireResearchSourceSnapshots;
use App\Domain\Intelligence\Services\ExtractAndValidateClaims;
use App\Domain\Intelligence\Services\PersistResearchSnapshots;
use App\Domain\Intelligence\Services\PreflightAccountResearch;
use App\Domain\Intelligence\Services\ProjectAccountIntelligence;
use App\Domain\Intelligence\Services\ReviewQualificationDecision;
use App\Domain\Intelligence\Services\StartAccountResearch;
use App\Models\Account;
use App\Models\AccountResearchRun;
use App\Models\AccountResearchStage;
use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvent;
use App\Models\ResearchClaimEvidence;
use App\Models\ResearchSourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('redacts expired source bodies and invalidates dependent claim and qualification projections', function (): void {
    [$user, $workspace, $account, $run, $stage] = retentionS2Fixture();
    $snapshot = app(PersistResearchSnapshots::class)->handle($workspace, $run, $stage, [[
        'source_type' => 'website',
        'url' => 'https://stripe.com/about',
        'content' => 'private source body that must be removed',
        'metadata' => ['payload_reference' => 'object://restricted-source-body'],
        'result_identity' => 'retention-source-1',
    ]])[0];
    $claim = app(ExtractAndValidateClaims::class)->handle($workspace, $run, $stage, [[
        'predicate' => 'company.category',
        'value' => 'workflow software',
        'value_type' => 'string',
        'confidence' => 0.92,
        'evidence' => [['snapshot_id' => (string) $snapshot->id, 'locator' => 'about paragraph']],
    ]])[0];
    $rubric = app(EnsureQualificationRubric::class)->handle(
        $workspace,
        $user,
        $run->gtmProfileVersion,
        null,
        ['required_facts' => ['company.category'], 'disqualifiers' => []],
    );
    $decision = app(EvaluateAccountQualification::class)->handle($workspace, $run, $rubric);
    app(ReviewQualificationDecision::class)->handle(
        $workspace,
        $decision,
        $user,
        QualificationReviewAction::Accept,
        'Retained before source expiry.',
    );

    $beforeHash = (string) $snapshot->content_hash;
    DB::table('research_source_snapshots')->where('id', $snapshot->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    expect(app(ExpireResearchSourceSnapshots::class)->handle($workspace, 10))->toBe(1);

    $redacted = $snapshot->fresh();
    $projected = app(ProjectAccountIntelligence::class)->handle($workspace, $account);
    $claimEvent = ResearchClaimEvent::query()
        ->where('workspace_id', $workspace->id)
        ->where('claim_id', $claim->id)
        ->latest('created_at')
        ->firstOrFail();
    $updatedDecision = $decision->fresh();

    expect($redacted->content)->toBeNull()
        ->and($redacted->payload_reference)->toBeNull()
        ->and($redacted->getRawOriginal('state'))->toBe('redacted')
        ->and($redacted->safe_failure_code)->toBe('retention_expired')
        ->and($redacted->redacted_at)->not->toBeNull()
        ->and($redacted->content_hash)->toBe($beforeHash)
        ->and($redacted->canonical_public_url)->toBe('https://stripe.com/about')
        ->and(ResearchClaim::query()->whereKey($claim->id)->exists())->toBeTrue()
        ->and(ResearchClaimEvidence::query()->where('claim_id', $claim->id)->count())->toBe(1)
        ->and($projected['claims'][0]['claim_id'])->toBe((string) $claim->id)
        ->and($projected['claims'][0]['status'])->toBe('stale')
        ->and($projected['claims'][0]['eligible_for_qualification'])->toBeFalse()
        ->and($claimEvent->getRawOriginal('action'))->toBe('policy_redact')
        ->and($claimEvent->prior_status)->toBe('supported')
        ->and($claimEvent->projected_status)->toBe('stale')
        ->and($updatedDecision->getRawOriginal('review_state'))->toBe('research_requested')
        ->and($updatedDecision->reviewEvents)->toHaveCount(2);
});

it('is idempotent, bounded, and workspace-scoped', function (): void {
    [$owner, $workspace, $account, $run, $stage] = retentionS2Fixture();
    $other = retentionS2Fixture();

    foreach (['retention-source-a', 'retention-source-b'] as $identity) {
        app(PersistResearchSnapshots::class)->handle($workspace, $run, $stage, [[
            'source_type' => 'website',
            'url' => 'https://stripe.com/'.$identity,
            'content' => 'workspace-owned source body',
            'result_identity' => $identity,
            'expires_at' => now()->subMinute()->toIso8601String(),
        ]]);
    }
    $otherSnapshot = app(PersistResearchSnapshots::class)->handle($other[1], $other[3], $other[4], [[
        'source_type' => 'website',
        'url' => 'https://stripe.com/foreign-retention',
        'content' => 'foreign workspace source body',
        'result_identity' => 'foreign-retention-source',
        'expires_at' => now()->subMinute()->toIso8601String(),
    ]])[0];

    expect(app(ExpireResearchSourceSnapshots::class)->handle($workspace, 1))->toBe(1)
        ->and(ResearchSourceSnapshot::query()->where('workspace_id', $workspace->id)->where('state', 'redacted')->count())->toBe(1)
        ->and($otherSnapshot->fresh()->content)->toBe('foreign workspace source body')
        ->and(app(ExpireResearchSourceSnapshots::class)->handle($workspace, 10))->toBe(1)
        ->and(app(ExpireResearchSourceSnapshots::class)->handle($workspace, 10))->toBe(0)
        ->and(ResearchSourceSnapshot::query()->where('workspace_id', $workspace->id)->where('state', 'redacted')->count())->toBe(2)
        ->and(ResearchClaimEvent::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
});

it('registers the per-workspace S2 retention schedule and bounded command', function (): void {
    $events = collect(app(Schedule::class)->events())->keyBy('description');
    expect($events->get('outboss-expire-research-source-snapshots'))->not->toBeNull()
        ->and($events->get('outboss-expire-research-source-snapshots')->withoutOverlapping)->toBeTrue();

    [$user, $workspace, $account, $run, $stage] = retentionS2Fixture();
    $snapshot = app(PersistResearchSnapshots::class)->handle($workspace, $run, $stage, [[
        'source_type' => 'website',
        'url' => 'https://stripe.com/command-retention',
        'content' => 'command source body',
        'result_identity' => 'command-retention-source',
        'expires_at' => now()->subMinute()->toIso8601String(),
    ]])[0];

    $exitCode = Artisan::call('odg-s2:expire-snapshots', [
        '--workspace' => (string) $workspace->id,
        '--limit' => '1',
        '--json' => true,
    ]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($result)->toMatchArray(['workspace_id' => $workspace->id, 'redacted' => 1])
        ->and($snapshot->fresh()->content)->toBeNull();
});

/** @return array{0:User,1:Workspace,2:Account,3:AccountResearchRun,4:AccountResearchStage} */
function retentionS2Fixture(): array
{
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Retention Fixture Account',
        'website' => 'https://stripe.com',
        'domain_normalized' => 'stripe.com',
        'created_by_user_id' => $user->id,
    ]);
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account);
    $run = app(StartAccountResearch::class)->handle(new StartAccountResearchRequest(
        workspace: $workspace,
        account: $account,
        actor: $user,
        idempotencyKey: 'retention-fixture-'.Str::uuid()->toString(),
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    ));
    $stage = $run->stages()->where('stage_key', 'website_retrieval')->firstOrFail();

    return [$user, $workspace->fresh(), $account->fresh(), $run->fresh(), $stage->fresh()];
}
