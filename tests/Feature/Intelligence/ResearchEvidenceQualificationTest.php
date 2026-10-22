<?php

use App\Domain\Intelligence\DTOs\QualificationEvaluation;
use App\Domain\Intelligence\DTOs\StartAccountResearchRequest;
use App\Domain\Intelligence\Enums\QualificationOutcome;
use App\Domain\Intelligence\Enums\QualificationReviewAction;
use App\Domain\Intelligence\Enums\QualificationReviewState;
use App\Domain\Intelligence\Services\CorrectResearchClaim;
use App\Domain\Intelligence\Services\EnsureQualificationRubric;
use App\Domain\Intelligence\Services\EvaluateAccountQualification;
use App\Domain\Intelligence\Services\ExtractAndValidateClaims;
use App\Domain\Intelligence\Services\PersistResearchSnapshots;
use App\Domain\Intelligence\Services\PreflightAccountResearch;
use App\Domain\Intelligence\Services\ProjectAccountIntelligence;
use App\Domain\Intelligence\Services\ReviewQualificationDecision;
use App\Domain\Intelligence\Services\StartAccountResearch;
use App\Models\ResearchClaim;
use Illuminate\Validation\ValidationException;

it('keeps source evidence and qualification history durable and reviewable', function () {
    [$user, $workspace, $account] = makeS2Fixture();
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account);
    $run = app(StartAccountResearch::class)->handle(new StartAccountResearchRequest(
        workspace: $workspace,
        account: $account,
        actor: $user,
        idempotencyKey: 'evidence-run-1',
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    ));
    $stage = $run->stages()->where('stage_key', 'website_retrieval')->firstOrFail();

    $snapshot = app(PersistResearchSnapshots::class)->handle($workspace, $run, $stage, [[
        'source_type' => 'website',
        'url' => 'https://stripe.com/about',
        'content' => 'Acme Software provides workflow automation for small businesses.',
        'result_identity' => 'fixture-about-page',
    ]])[0];
    $claim = app(ExtractAndValidateClaims::class)->handle($workspace, $run, $stage, [[
        'predicate' => 'company.category',
        'value' => 'workflow software',
        'value_type' => 'string',
        'confidence' => 0.92,
        'evidence' => [['snapshot_id' => (string) $snapshot->id, 'locator' => 'about paragraph']],
    ]])[0];

    expect($snapshot->evidence)->toHaveCount(1)
        ->and($claim->evidence)->toHaveCount(1)
        ->and(fn () => $snapshot->update(['content' => 'rewritten']))->toThrow(LogicException::class)
        ->and(fn () => $claim->update(['confidence' => 0.1]))->toThrow(LogicException::class);

    $successor = app(CorrectResearchClaim::class)->handle(
        $workspace,
        $claim,
        $user,
        'business workflow software',
        'Owner verified the category.',
    );
    $projection = app(ProjectAccountIntelligence::class)->handle($workspace, $account);

    $rubric = app(EnsureQualificationRubric::class)->handle(
        $workspace,
        $user,
        $run->gtmProfileVersion,
        null,
        ['required_facts' => ['company.category'], 'disqualifiers' => []],
    );
    $decision = app(EvaluateAccountQualification::class)->handle($workspace, $run, $rubric);
    $invalidCorrection = new QualificationEvaluation(
        outcome: QualificationOutcome::Contact,
        confidence: 0.95,
        calibrationBand: 'high',
        explanation: 'An invalid role must not be persisted.',
        claims: [['claim_id' => (string) $successor->id, 'role' => 'invented_role']],
    );
    expect(fn () => app(ReviewQualificationDecision::class)->handle(
        $workspace,
        $decision,
        $user,
        QualificationReviewAction::Correct,
        'Attempt an invalid correction.',
        $invalidCorrection,
    ))->toThrow(ValidationException::class);
    $reviewed = app(ReviewQualificationDecision::class)->handle(
        $workspace,
        $decision,
        $user,
        QualificationReviewAction::Accept,
        'Reviewed source and owner correction.',
    );

    expect($successor->supersedes_claim_id)->toBe($claim->id)
        ->and($projection['claims'])->toHaveCount(1)
        ->and($projection['claims'][0]['status'])->toBe('owner_confirmed')
        ->and($projection['claims'][0]['claim_id'])->toBe($successor->id)
        ->and($decision->outcome)->toBe(QualificationOutcome::Contact)
        ->and($decision->reasonsToContact)->toHaveCount(1)
        ->and($reviewed->review_state)->toBe(QualificationReviewState::Accepted)
        ->and($decision->reviewEvents)->toHaveCount(1)
        ->and(ResearchClaim::query()->count())->toBe(2);
});
