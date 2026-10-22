<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\DTOs\QualificationEvaluation;
use App\Domain\Intelligence\Enums\QualificationDecisionClaimRole;
use App\Domain\Intelligence\Enums\QualificationOutcome;
use App\Domain\Intelligence\Enums\QualificationReviewAction;
use App\Domain\Intelligence\Enums\QualificationReviewState;
use App\Domain\Intelligence\Support\CanonicalIntelligence;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\QualificationDecision;
use App\Models\QualificationDecisionClaim;
use App\Models\QualificationReviewEvent;
use App\Models\ResearchClaim;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Append review history while keeping the generated decision content intact. */
final class ReviewQualificationDecision
{
    public function handle(
        Workspace $workspace,
        QualificationDecision|string $decision,
        User $actor,
        QualificationReviewAction|string $action,
        ?string $reason = null,
        ?QualificationEvaluation $correction = null,
    ): QualificationDecision {
        if (! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can review qualification decisions.');
        }
        $resolvedAction = $action instanceof QualificationReviewAction ? $action : QualificationReviewAction::tryFrom($action);
        if ($resolvedAction === null) {
            throw ValidationException::withMessages(['action' => 'The qualification review action is invalid.']);
        }
        $decisionId = $decision instanceof QualificationDecision ? (string) $decision->id : $decision;

        return DB::transaction(function () use ($workspace, $decisionId, $actor, $resolvedAction, $reason, $correction): QualificationDecision {
            $locked = QualificationDecision::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($decisionId)
                ->lockForUpdate()
                ->firstOrFail();
            $prior = ModelAttribute::enumValue($locked->review_state) ?? 'pending';
            if ($resolvedAction === QualificationReviewAction::Correct) {
                $successor = $this->successor($workspace, $locked, $correction);
                $this->setProjection($locked, QualificationReviewState::Corrected);
                $this->event($workspace, $locked, $actor, $resolvedAction, $reason, $prior, QualificationReviewState::Corrected, $successor->id, null);

                return $successor->fresh();
            }

            $state = match ($resolvedAction) {
                QualificationReviewAction::Accept => QualificationReviewState::Accepted,
                QualificationReviewAction::Reject => QualificationReviewState::Rejected,
                QualificationReviewAction::RequestResearch => QualificationReviewState::ResearchRequested,
                QualificationReviewAction::Defect => QualificationReviewState::Pending,
            };
            $this->setProjection($locked, $state);
            $this->event($workspace, $locked, $actor, $resolvedAction, $reason, $prior, $state, null, null);

            return $locked->fresh();
        }, 3);
    }

    public function review(
        Workspace $workspace,
        QualificationDecision|string $decision,
        User $actor,
        QualificationReviewAction|string $action,
        ?string $reason = null,
        ?QualificationEvaluation $correction = null,
    ): QualificationDecision {
        return $this->handle($workspace, $decision, $actor, $action, $reason, $correction);
    }

    private function setProjection(QualificationDecision $decision, QualificationReviewState $state): void
    {
        $decision->forceFill([
            'review_state' => $state,
            'reviewed_at' => now(),
            'corrected_at' => $state === QualificationReviewState::Corrected ? now() : $decision->corrected_at,
        ])->save();
    }

    private function successor(Workspace $workspace, QualificationDecision $prior, ?QualificationEvaluation $correction): QualificationDecision
    {
        $outcome = $correction !== null
            ? $correction->outcome
            : (QualificationOutcome::tryFrom(ModelAttribute::enumValue($prior->outcome) ?? '') ?? QualificationOutcome::NeedsReview);
        $claims = $correction !== null
            ? $this->normalizeClaims($correction->claims)
            : $this->existingClaims($prior);
        $inputHash = CanonicalIntelligence::hash([
            'prior_decision_id' => (string) $prior->id,
            'outcome' => $outcome->value,
            'claims' => $claims,
            'reason' => $correction === null ? null : $correction->explanation,
        ]);
        $existing = QualificationDecision::query()->where('workspace_id', $workspace->id)->where('input_hash', $inputHash)->first();
        if ($existing !== null) {
            return $existing;
        }
        $successor = QualificationDecision::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'account_id' => $prior->account_id,
            'research_run_id' => $prior->research_run_id,
            'rubric_version_id' => $prior->rubric_version_id,
            'gtm_profile_version_id' => $prior->gtm_profile_version_id,
            'offer_version_id' => $prior->offer_version_id,
            'prior_decision_id' => $prior->id,
            'input_hash' => $inputHash,
            'outcome' => $outcome,
            'confidence' => $correction !== null ? $correction->confidence : $prior->confidence,
            'calibration_band' => $correction !== null ? $correction->calibrationBand : $prior->calibration_band,
            'missing_facts' => $correction !== null ? $correction->missingFacts : $prior->missing_facts,
            'disqualifiers' => $correction !== null ? $correction->disqualifiers : $prior->disqualifiers,
            'explanation' => $correction !== null ? $correction->explanation : $prior->explanation,
            'model_version' => null,
            'prompt_version' => null,
            'policy_version' => 'review-correction-v1',
            'catalogue_version' => null,
            'provider_operation_id' => null,
            'provider_cost_micros' => 0,
            'review_state' => QualificationReviewState::Pending,
            'content_hash' => CanonicalIntelligence::hash(['input_hash' => $inputHash, 'outcome' => $outcome->value, 'explanation' => $correction !== null ? $correction->explanation : $prior->explanation]),
            'generated_at' => now(),
            'reviewed_at' => null,
            'corrected_at' => null,
        ]);
        foreach ($claims as $link) {
            if (! ResearchClaim::query()->where('workspace_id', $workspace->id)->whereKey($link['claim_id'])->exists()) {
                continue;
            }
            QualificationDecisionClaim::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'decision_id' => $successor->id,
                'claim_id' => $link['claim_id'],
                'role' => $link['role'],
            ]);
        }

        return $successor;
    }

    /** @return list<array{claim_id:string,role:string}> */
    private function existingClaims(QualificationDecision $decision): array
    {
        $links = QualificationDecisionClaim::query()->where('decision_id', $decision->id)->get();
        $claims = [];
        foreach ($links as $link) {
            $claims[] = [
                'claim_id' => (string) $link->claim_id,
                'role' => ModelAttribute::enumValue($link->role) ?? 'supporting',
            ];
        }

        return $claims;
    }

    /** @return list<array{claim_id:string,role:string}> */
    private function normalizeClaims(mixed $claims): array
    {
        if (! is_array($claims)) {
            return [];
        }

        $normalized = [];
        foreach ($claims as $link) {
            if (! is_array($link) || ! is_string($link['claim_id'] ?? null)) {
                continue;
            }
            $role = is_string($link['role'] ?? null)
                ? QualificationDecisionClaimRole::tryFrom($link['role'])
                : null;
            if ($role === null) {
                throw ValidationException::withMessages([
                    'correction.claims' => 'Every qualification Claim role must be one of the approved roles.',
                ]);
            }
            $normalized[] = [
                'claim_id' => $link['claim_id'],
                'role' => $role->value,
            ];
        }

        return $normalized;
    }

    private function event(
        Workspace $workspace,
        QualificationDecision $decision,
        User $actor,
        QualificationReviewAction $action,
        ?string $reason,
        string $prior,
        QualificationReviewState $projected,
        ?string $successorDecisionId,
        ?string $successorRunId,
    ): void {
        QualificationReviewEvent::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'account_id' => $decision->account_id,
            'decision_id' => $decision->id,
            'action' => $action,
            'actor_snapshot' => ['user_id' => (int) $actor->id, 'display_name' => mb_substr((string) $actor->name, 0, 160)],
            'actor_user_id' => $actor->id,
            'reason' => $reason,
            'prior_review_state' => $prior,
            'projected_review_state' => $projected->value,
            'successor_decision_id' => $successorDecisionId,
            'successor_run_id' => $successorRunId,
            'created_at' => now(),
        ]);
    }
}
