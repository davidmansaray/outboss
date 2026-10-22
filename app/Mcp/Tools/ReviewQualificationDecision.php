<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Intelligence\DTOs\QualificationEvaluation;
use App\Domain\Intelligence\Enums\QualificationOutcome;
use App\Domain\Intelligence\Services\ReviewQualificationDecision as ReviewService;
use App\Http\Resources\Api\V1\QualificationDecisionResource;
use App\Models\Account;
use App\Models\QualificationDecision;
use App\Models\ResearchClaim;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Records an Account qualification review or bounded correction. Review writes require workspace membership; correction links are restricted to claims from the same Account and workspace.')]
class ReviewQualificationDecision extends OutbossTool
{
    public function __construct(private readonly ReviewService $review) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'decision_id' => $schema->string()->required()->description('The qualification decision UUID.'),
            'action' => $schema->string()->required()->description('accept, correct, reject, request_research, or defect.'),
            'reason' => $schema->string()->max(2000)->description('Optional bounded review reason.'),
            'correction' => $schema->object()->description('Optional bounded correction object used when action is correct.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::IntelligenceWrite->value)) {
            return $denied;
        }

        try {
            $user = $this->actor($request);
            $workspace = $user->currentWorkspace();
            $decision = QualificationDecision::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey((string) $request->get('decision_id'))
                ->with('claimLinks')
                ->first();
            if ($decision === null) {
                throw new AuthorizationException('This action is unauthorized.');
            }
            $account = Account::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($decision->account_id)
                ->first();
            if ($account === null) {
                throw new AuthorizationException('This action is unauthorized.');
            }
            Gate::forUser($user)->authorize('update', $account);
            $data = $request->validate([
                'decision_id' => ['required', 'uuid'],
                'action' => ['required', 'string', 'in:accept,correct,reject,request_research,defect'],
                'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'correction' => ['sometimes', 'nullable', 'array', 'max:20'],
                'correction.outcome' => ['sometimes', 'string', 'in:contact,withhold,research_further,insufficient_evidence,needs_review'],
                'correction.confidence' => ['sometimes', 'numeric', 'min:0', 'max:1'],
                'correction.calibration_band' => ['sometimes', 'nullable', 'string', 'in:low,medium,high'],
                'correction.missing_facts' => ['sometimes', 'array', 'max:50'],
                'correction.missing_facts.*' => ['string', 'max:160'],
                'correction.disqualifiers' => ['sometimes', 'array', 'max:50'],
                'correction.disqualifiers.*' => ['string', 'max:160'],
                'correction.explanation' => ['sometimes', 'string', 'max:4000'],
                'correction.claims' => ['sometimes', 'array', 'max:100'],
                'correction.claims.*.claim_id' => ['required', 'uuid'],
                'correction.claims.*.role' => ['required', 'string', 'in:required,supporting,contradicting,missing,disqualifying'],
                'correction.sensitive_inference' => ['sometimes', 'boolean'],
                'correction.unsupported_causality' => ['sometimes', 'boolean'],
                'correction.why_account' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'correction.why_offer' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'correction.why_now' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'correction.factual_basis' => ['sometimes', 'nullable', 'string', 'max:4000'],
                'correction.internal_rationale' => ['sometimes', 'nullable', 'string', 'max:4000'],
            ]);
            $evaluation = null;
            if ($data['action'] === 'correct') {
                $correction = is_array($data['correction'] ?? null) ? $data['correction'] : [];
                $outcome = QualificationOutcome::tryFrom((string) ($correction['outcome'] ?? $decision->getRawOriginal('outcome')));
                if ($outcome === null) {
                    throw ValidationException::withMessages(['correction.outcome' => 'A valid qualification outcome is required.']);
                }
                $claimIds = array_values(array_filter(array_map(
                    static fn (mixed $claim): ?string => is_array($claim) && is_string($claim['claim_id'] ?? null) ? $claim['claim_id'] : null,
                    is_array($correction['claims'] ?? null) ? $correction['claims'] : [],
                )));
                $allowedIds = ResearchClaim::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('account_id', $decision->account_id)
                    ->whereIn('id', $claimIds)
                    ->pluck('id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all();
                $claims = array_values(array_filter(
                    $this->qualificationClaims($correction['claims'] ?? null, 'correction.claims'),
                    static fn (array $claim): bool => in_array($claim['claim_id'], $allowedIds, true),
                ));
                $evaluation = new QualificationEvaluation(
                    outcome: $outcome,
                    confidence: isset($correction['confidence']) ? (float) $correction['confidence'] : (float) $decision->confidence,
                    calibrationBand: $correction['calibration_band'] ?? $decision->calibration_band,
                    missingFacts: $this->stringList($correction['missing_facts'] ?? $decision->missing_facts, 'correction.missing_facts'),
                    disqualifiers: $this->stringList($correction['disqualifiers'] ?? $decision->disqualifiers, 'correction.disqualifiers'),
                    explanation: (string) ($correction['explanation'] ?? $decision->explanation),
                    claims: $claims,
                    sensitiveInference: (bool) ($correction['sensitive_inference'] ?? false),
                    unsupportedCausality: (bool) ($correction['unsupported_causality'] ?? false),
                    whyAccount: $correction['why_account'] ?? null,
                    whyOffer: $correction['why_offer'] ?? null,
                    whyNow: $correction['why_now'] ?? null,
                    factualBasis: $correction['factual_basis'] ?? null,
                    internalRationale: $correction['internal_rationale'] ?? null,
                );
            }
            $updated = $this->review->handle(
                $workspace,
                $decision,
                $user,
                $data['action'],
                $data['reason'] ?? null,
                $evaluation,
            );

            return $this->json((new QualificationDecisionResource($updated->load(['claimLinks', 'reasonsToContact', 'reviewEvents'])))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
