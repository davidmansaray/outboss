<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Intelligence\DTOs\QualificationEvaluation;
use App\Domain\Intelligence\DTOs\StartAccountResearchRequest as StartAccountResearchDto;
use App\Domain\Intelligence\Enums\QualificationOutcome;
use App\Domain\Intelligence\Services\CancelAccountResearch;
use App\Domain\Intelligence\Services\CorrectResearchClaim;
use App\Domain\Intelligence\Services\PreflightAccountResearch;
use App\Domain\Intelligence\Services\ProjectAccountIntelligence;
use App\Domain\Intelligence\Services\ReviewQualificationDecision;
use App\Domain\Intelligence\Services\StartAccountResearch;
use App\Http\Controllers\Api\V1\Concerns\HandlesS2Transport;
use App\Http\Requests\AccountResearchPreflightRequest;
use App\Http\Requests\CorrectResearchClaimRequest;
use App\Http\Requests\ReviewQualificationDecisionRequest;
use App\Http\Requests\StartAccountResearchRequest;
use App\Http\Resources\Api\V1\AccountIntelligenceResource;
use App\Http\Resources\Api\V1\AccountResearchPreflightResource;
use App\Http\Resources\Api\V1\AccountResearchRunResource;
use App\Http\Resources\Api\V1\QualificationDecisionResource;
use App\Http\Resources\Api\V1\ResearchClaimResource;
use App\Models\Account;
use App\Models\AccountResearchRun;
use App\Models\QualificationDecision;
use App\Models\ResearchClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountIntelligenceController
{
    use HandlesS2Transport;

    public function show(Request $request, string $account, ProjectAccountIntelligence $projection): AccountIntelligenceResource
    {
        $workspace = $request->user()->currentWorkspace();
        $accountModel = $this->account($workspace->id, $account);
        $this->authorizeFor($request, 'view', $accountModel);
        $intelligence = $projection->handle($workspace, $accountModel);

        $runs = AccountResearchRun::query()
            ->where('workspace_id', $workspace->id)
            ->where('account_id', $accountModel->id)
            ->with(['stages', 'qualificationDecisions'])
            ->latest('created_at')
            ->limit(20)
            ->get();
        $latestRun = $runs->first();
        $latestQualification = QualificationDecision::query()
            ->where('workspace_id', $workspace->id)
            ->where('account_id', $accountModel->id)
            ->with(['claimLinks', 'reasonsToContact', 'reviewEvents'])
            ->latest('generated_at')
            ->first();

        return new AccountIntelligenceResource([
            'account' => $this->accountIdentity($accountModel),
            'intelligence' => $intelligence,
            'latest_run' => $latestRun === null ? null : (new AccountResearchRunResource($latestRun))->resolve(),
            'runs' => AccountResearchRunResource::collection($runs)->resolve(),
            'latest_qualification' => $latestQualification === null ? null : (new QualificationDecisionResource($latestQualification))->resolve(),
        ]);
    }

    public function preflight(
        AccountResearchPreflightRequest $request,
        string $account,
        PreflightAccountResearch $preflight,
    ): JsonResponse|AccountResearchPreflightResource {
        $workspace = $request->user()->currentWorkspace();
        $accountModel = $this->account($workspace->id, $account);
        $this->authorizeFor($request, 'view', $accountModel);
        $data = $request->validated();
        try {
            $preview = $preflight->handle(
                $workspace,
                $accountModel,
                $data['gtm_profile_version_id'] ?? null,
                $data['offer_version_id'] ?? null,
                is_array($data['freshness_policy'] ?? null) ? $data['freshness_policy'] : [],
                $this->stringList($data['capabilities'] ?? []),
                isset($data['maximum_cost_micros']) ? (int) $data['maximum_cost_micros'] : null,
            );
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new AccountResearchPreflightResource($preview);
    }

    public function start(
        StartAccountResearchRequest $request,
        string $account,
        StartAccountResearch $start,
    ): JsonResponse|AccountResearchRunResource {
        $workspace = $request->user()->currentWorkspace();
        $accountModel = $this->account($workspace->id, $account);
        $this->authorizeFor($request, 'view', $accountModel);
        $data = $request->validated();
        $key = $this->idempotencyKey($request, true);

        try {
            $run = $start->start(new StartAccountResearchDto(
                workspace: $workspace,
                account: $accountModel,
                actor: $request->user(),
                idempotencyKey: (string) $key,
                gtmProfileVersionId: $data['gtm_profile_version_id'] ?? null,
                offerVersionId: $data['offer_version_id'] ?? null,
                freshnessPolicy: is_array($data['freshness_policy'] ?? null) ? $data['freshness_policy'] : [],
                capabilities: $this->stringList($data['capabilities'] ?? []),
                maximumCostMicros: isset($data['maximum_cost_micros']) ? (int) $data['maximum_cost_micros'] : 1_500_000,
                preflightHash: $data['preflight_hash'] ?? null,
            ));
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return (new AccountResearchRunResource($run->load(['stages'])))->response()->setStatusCode(202);
    }

    public function runs(Request $request): AnonymousResourceCollection
    {
        $workspace = $request->user()->currentWorkspace();
        $query = AccountResearchRun::query()->where('workspace_id', $workspace->id);
        if ($request->filled('account_id')) {
            $query->where('account_id', (int) $request->integer('account_id'));
        }
        $runs = $query->with('stages')->latest('created_at')->paginate($this->perPage($request));

        return AccountResearchRunResource::collection($runs);
    }

    public function run(Request $request, string $run): AccountResearchRunResource
    {
        $workspace = $request->user()->currentWorkspace();
        $researchRun = AccountResearchRun::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey(strtolower($run))
            ->with(['stages.attempts', 'attempts', 'snapshots.evidence', 'claims.evidence', 'qualificationDecisions.claimLinks', 'qualificationDecisions.reasonsToContact', 'qualificationDecisions.reviewEvents'])
            ->firstOrFail();
        $account = $this->account($workspace->id, (string) $researchRun->account_id);
        $this->authorizeFor($request, 'view', $account);

        return new AccountResearchRunResource($researchRun);
    }

    public function cancel(Request $request, string $run, CancelAccountResearch $cancel): JsonResponse|AccountResearchRunResource
    {
        $workspace = $request->user()->currentWorkspace();
        $researchRun = AccountResearchRun::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey(strtolower($run))
            ->firstOrFail();
        $account = $this->account($workspace->id, (string) $researchRun->account_id);
        $this->authorizeFor($request, 'view', $account);

        try {
            $updated = $cancel->handle($workspace, $researchRun, $request->user());
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new AccountResearchRunResource($updated->load('stages'));
    }

    public function claims(Request $request): AnonymousResourceCollection
    {
        $workspace = $request->user()->currentWorkspace();
        $query = ResearchClaim::query()->where('workspace_id', $workspace->id);
        if ($request->filled('account_id')) {
            $query->where('account_id', (int) $request->integer('account_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        $claims = $query->with('evidence')->latest('created_at')->paginate($this->perPage($request));

        return ResearchClaimResource::collection($claims);
    }

    public function claim(Request $request, string $claim): ResearchClaimResource
    {
        $workspace = $request->user()->currentWorkspace();
        $model = ResearchClaim::query()->where('workspace_id', $workspace->id)->whereKey(strtolower($claim))->with('evidence')->firstOrFail();
        $account = $this->account($workspace->id, (string) $model->account_id);
        $this->authorizeFor($request, 'view', $account);

        return new ResearchClaimResource($model);
    }

    public function correctClaim(
        CorrectResearchClaimRequest $request,
        string $claim,
        CorrectResearchClaim $correct,
    ): JsonResponse|ResearchClaimResource {
        $workspace = $request->user()->currentWorkspace();
        $model = ResearchClaim::query()->where('workspace_id', $workspace->id)->whereKey(strtolower($claim))->firstOrFail();
        $account = $this->account($workspace->id, (string) $model->account_id);
        $this->authorizeFor($request, 'update', $account);
        $data = $request->validated();
        $encoded = json_encode($data['value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > 16_384) {
            throw ValidationException::withMessages(['value' => 'The correction value exceeds the bounded size limit.']);
        }

        try {
            $successor = $correct->handle(
                $workspace,
                $model,
                $request->user(),
                $data['value'],
                $data['reason'] ?? null,
                $data['value_type'] ?? null,
            );
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new ResearchClaimResource($successor->load('evidence'));
    }

    public function decisions(Request $request): AnonymousResourceCollection
    {
        $workspace = $request->user()->currentWorkspace();
        $query = QualificationDecision::query()->where('workspace_id', $workspace->id);
        if ($request->filled('account_id')) {
            $query->where('account_id', (int) $request->integer('account_id'));
        }
        if ($request->filled('review_state')) {
            $query->where('review_state', (string) $request->query('review_state'));
        }
        $decisions = $query->with(['claimLinks', 'reasonsToContact', 'reviewEvents'])->latest('generated_at')->paginate($this->perPage($request));

        return QualificationDecisionResource::collection($decisions);
    }

    public function decision(Request $request, string $decision): QualificationDecisionResource
    {
        $workspace = $request->user()->currentWorkspace();
        $model = QualificationDecision::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey(strtolower($decision))
            ->with(['claimLinks', 'reasonsToContact', 'reviewEvents'])
            ->firstOrFail();
        $account = $this->account($workspace->id, (string) $model->account_id);
        $this->authorizeFor($request, 'view', $account);

        return new QualificationDecisionResource($model);
    }

    public function reviewDecision(
        ReviewQualificationDecisionRequest $request,
        string $decision,
        ReviewQualificationDecision $review,
    ): JsonResponse|QualificationDecisionResource {
        $workspace = $request->user()->currentWorkspace();
        $model = QualificationDecision::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey(strtolower($decision))
            ->with('claimLinks')
            ->firstOrFail();
        $account = $this->account($workspace->id, (string) $model->account_id);
        $this->authorizeFor($request, 'update', $account);
        $data = $request->validated();
        $evaluation = null;
        if (($data['action'] ?? null) === 'correct') {
            $correction = is_array($data['correction'] ?? null) ? $data['correction'] : [];
            $outcome = QualificationOutcome::tryFrom((string) ($correction['outcome'] ?? $model->getRawOriginal('outcome')));
            if ($outcome === null) {
                throw ValidationException::withMessages(['correction.outcome' => 'A valid qualification outcome is required.']);
            }
            $claims = is_array($correction['claims'] ?? null) ? $correction['claims'] : [];
            $allowedClaimIds = ResearchClaim::query()
                ->where('workspace_id', $workspace->id)
                ->where('account_id', $model->account_id)
                ->whereIn('id', array_values(array_filter(array_map(
                    static fn (mixed $claim): ?string => is_array($claim) && is_string($claim['claim_id'] ?? null) ? $claim['claim_id'] : null,
                    $claims,
                ))))
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();
            $claims = $this->qualificationClaims($claims, $allowedClaimIds);
            $evaluation = new QualificationEvaluation(
                outcome: $outcome,
                confidence: isset($correction['confidence']) ? (float) $correction['confidence'] : (float) $model->confidence,
                calibrationBand: $correction['calibration_band'] ?? $model->calibration_band,
                missingFacts: $this->stringList($correction['missing_facts'] ?? $model->missing_facts),
                disqualifiers: $this->stringList($correction['disqualifiers'] ?? $model->disqualifiers),
                explanation: (string) ($correction['explanation'] ?? $model->explanation),
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

        try {
            $updated = $review->handle(
                $workspace,
                $model,
                $request->user(),
                (string) $data['action'],
                $data['reason'] ?? null,
                $evaluation,
            );
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new QualificationDecisionResource($updated->load(['claimLinks', 'reasonsToContact', 'reviewEvents']));
    }

    private function account(int|string $workspaceId, string $account): Account
    {
        return Account::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey(ctype_digit($account) ? (int) $account : $account)
            ->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function accountIdentity(Account $account): array
    {
        return [
            'id' => (int) $account->id,
            'name' => mb_substr((string) $account->name, 0, 240),
            'website' => $account->website,
            'domain' => $account->domain_normalized,
            'tags' => array_slice($account->tags, 0, 50),
            'source' => $account->source,
            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }

    private function authorizeFor(Request $request, string $ability, Account $account): void
    {
        $this->authorize($ability, $account);
    }

    private function authorize(string $ability, Account $account): void
    {
        Gate::forUser(request()->user())->authorize($ability, $account);
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, $request->integer('per_page', 20)));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @param  array<mixed>  $claims
     * @param  array<string>  $allowedClaimIds
     * @return list<array{claim_id:string,role:string}>
     */
    private function qualificationClaims(array $claims, array $allowedClaimIds): array
    {
        $permitted = [];
        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                continue;
            }
            $claimId = $claim['claim_id'] ?? null;
            $role = $claim['role'] ?? null;
            if (is_string($claimId) && is_string($role) && in_array($claimId, $allowedClaimIds, true)) {
                $permitted[] = ['claim_id' => $claimId, 'role' => $role];
            }
        }

        return $permitted;
    }
}
