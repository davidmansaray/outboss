<?php

namespace App\Http\Controllers;

use App\Domain\Gtm\Services\EvaluateOfferReadiness;
use App\Domain\Gtm\Services\ReviewOfferVersion;
use App\Domain\Intelligence\DTOs\QualificationEvaluation;
use App\Domain\Intelligence\DTOs\StartAccountResearchRequest;
use App\Domain\Intelligence\Enums\QualificationOutcome;
use App\Domain\Intelligence\Enums\QualificationReviewAction;
use App\Domain\Intelligence\Exceptions\AccountResearchPaused;
use App\Domain\Intelligence\Services\CancelAccountResearch;
use App\Domain\Intelligence\Services\CorrectResearchClaim;
use App\Domain\Intelligence\Services\PreflightAccountResearch;
use App\Domain\Intelligence\Services\ProjectAccountIntelligence;
use App\Domain\Intelligence\Services\RefreshAccountResearch;
use App\Domain\Intelligence\Services\ReviewQualificationDecision;
use App\Domain\Intelligence\Services\StartAccountResearch;
use App\Domain\People\Support\ProvenanceSummary;
use App\Models\Account;
use App\Models\AccountResearchRun;
use App\Models\ConversionDefinition;
use App\Models\ConversionEvent;
use App\Models\Offer;
use App\Models\OfferReadinessEvaluation;
use App\Models\OfferVersion;
use App\Models\QualificationDecision;
use App\Models\QualificationReviewEvent;
use App\Models\ReasonToContactVersion;
use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvent;
use App\Models\ResearchSourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Browser surface for the one-Account S2 intelligence workflow.
 *
 * This controller deliberately owns the browser read model and session
 * redirects only. Research, evidence and qualification writes remain in the
 * domain services so retries and API callers share the same invariants.
 */
final class AccountIntelligenceController extends Controller
{
    public function __construct(
        private readonly PreflightAccountResearch $preflight,
        private readonly ProjectAccountIntelligence $projection,
        private readonly StartAccountResearch $start,
        private readonly RefreshAccountResearch $refresh,
        private readonly CancelAccountResearch $cancel,
        private readonly CorrectResearchClaim $correctClaim,
        private readonly ReviewQualificationDecision $reviewQualification,
        private readonly EvaluateOfferReadiness $offerReadiness,
        private readonly ReviewOfferVersion $reviewOffer,
    ) {}

    /**
     * Render the bounded, server-backed account intelligence read model.
     *
     * The optional `run` query value is useful when a browser reconnects while
     * a refresh is in flight. It never permits a run from another account or
     * workspace to be selected.
     */
    public function show(Request $request, int|string $account): Response|JsonResponse
    {
        [$user, $workspace, $accountModel] = $this->context($request, $account);
        $selectedRun = $this->selectedRun($request, $workspace, $accountModel);

        $preview = $this->preflight->handle(
            $workspace,
            $accountModel,
            $selectedRun?->gtm_profile_version_id,
            $selectedRun?->offer_version_id,
            $selectedRun !== null ? $this->associativeArray($selectedRun->freshness_policy) : [],
            $selectedRun !== null ? $this->stringList(data_get($selectedRun->capability_snapshot, 'selected', [])) : [],
            $selectedRun?->maximum_cost_micros,
        );
        $projected = $this->projection->handle($workspace, $accountModel);
        $claims = $this->claimsPayload($workspace, $projected);
        $runs = AccountResearchRun::query()
            ->where('workspace_id', $workspace->id)
            ->where('account_id', $accountModel->id)
            ->with('stages')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (AccountResearchRun $run): array => $this->runPayload($run))
            ->values()
            ->all();

        $decision = $this->decision($workspace, $accountModel, $selectedRun);

        $payload = [
            'account' => $this->accountPayload($accountModel),
            'provenance' => ProvenanceSummary::for($accountModel),
            'preflight' => $preview->toArray(),
            'researchRun' => $selectedRun === null ? null : $this->runPayload($selectedRun),
            'researchRuns' => $runs,
            'intelligence' => [
                'claims' => $claims,
                'by_status' => $this->claimsByStatus($claims),
                'status_counts' => $this->claimStatusCounts($claims),
                'conflicts' => array_values(array_filter($claims, static fn (array $claim): bool => $claim['status'] === 'conflicting')),
                'missing_facts' => $decision === null ? [] : $this->stringList($decision->missing_facts),
            ],
            'qualification' => $decision === null ? null : $this->decisionPayload($decision),
            'offer' => $this->offerPayload($workspace, $selectedRun),
            'conversion' => $this->conversionPayload($workspace, $accountModel),
            'selectedRunId' => $selectedRun?->id,
            'isOwner' => $workspace->isOwner($user),
            'canWrite' => $workspace->isOwner($user),
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('accounts/Intelligence', $payload);
    }

    /**
     * Run a side-effect-free preflight. Members may inspect the result; only
     * the start endpoint creates a durable run.
     */
    public function preflight(Request $request, int|string $account): JsonResponse|RedirectResponse
    {
        [, $workspace, $accountModel] = $this->context($request, $account);
        $data = $this->validatedResearchData($request, allowIdempotency: false);
        $preview = $this->preflight->handle(
            $workspace,
            $accountModel,
            $data['gtm_profile_version_id'] ?? null,
            $data['offer_version_id'] ?? null,
            $this->associativeArray($data['freshness_policy'] ?? []),
            $this->stringList($data['capabilities'] ?? []),
            isset($data['maximum_cost_micros']) ? (int) $data['maximum_cost_micros'] : null,
        );

        if ($request->expectsJson()) {
            return response()->json(['preflight' => $preview->toArray()]);
        }

        return redirect()->route('accounts.intelligence.show', ['account' => $accountModel->id]);
    }

    /** Start one bounded run after repeating the domain preflight under lock. */
    public function startResearch(Request $request, int|string $account): JsonResponse|RedirectResponse
    {
        [$user, $workspace, $accountModel] = $this->context($request, $account);
        $this->assertOwner($workspace, $user);
        $data = $this->validatedResearchData($request, allowIdempotency: true);
        $idempotencyKey = (string) ($data['idempotency_key'] ?? $data['client_operation_id'] ?? '');

        try {
            $run = $this->start->start(new StartAccountResearchRequest(
                workspace: $workspace,
                account: $accountModel,
                actor: $user,
                idempotencyKey: $idempotencyKey,
                gtmProfileVersionId: $data['gtm_profile_version_id'] ?? null,
                offerVersionId: $data['offer_version_id'] ?? null,
                freshnessPolicy: $this->associativeArray($data['freshness_policy'] ?? []),
                capabilities: $this->stringList($data['capabilities'] ?? []),
                maximumCostMicros: isset($data['maximum_cost_micros'])
                    ? (int) $data['maximum_cost_micros']
                    : 1_500_000,
                preflightHash: $data['preflight_hash'] ?? null,
            ));
        } catch (RuntimeException $exception) {
            return $this->mutationError($request, (int) $accountModel->id, $exception, 'Research could not be started.');
        }

        if ($request->expectsJson()) {
            return response()->json(['run' => $this->runPayload($run)]);
        }

        return redirect()
            ->route('accounts.intelligence.show', ['account' => $accountModel->id, 'run' => $run->id])
            ->with('success', 'Account research run queued.');
    }

    /** Select a run from a durable URL without rendering another page shape. */
    public function run(Request $request, int|string $account, string $run): JsonResponse|RedirectResponse
    {
        [, $workspace, $accountModel] = $this->context($request, $account);
        $selected = $this->runForAccount($workspace, $accountModel, $run);

        if ($request->expectsJson()) {
            return response()->json(['run' => $this->runPayload($selected)]);
        }

        return redirect()->route('accounts.intelligence.show', [
            'account' => $accountModel->id,
            'run' => $selected->id,
        ]);
    }

    /** Cancel a run while retaining all snapshots, claims and audit history. */
    public function cancelRun(Request $request, string $run): JsonResponse|RedirectResponse
    {
        [$user, $workspace] = $this->workspaceContext($request);
        $this->assertOwner($workspace, $user);
        $researchRun = $this->runForWorkspace($workspace, $run);
        $updated = $this->cancel->cancel($workspace, $researchRun, $user);

        if ($request->expectsJson()) {
            return response()->json(['run' => $this->runPayload($updated)]);
        }

        return redirect()->route('accounts.intelligence.show', [
            'account' => $updated->account_id,
            'run' => $updated->id,
        ])->with('success', 'Research cancellation requested. Its saved evidence remains available.');
    }

    /** Start a child run with the same frozen context for a safe refresh. */
    public function refreshRun(Request $request, string $run): JsonResponse|RedirectResponse
    {
        [$user, $workspace] = $this->workspaceContext($request);
        $this->assertOwner($workspace, $user);
        $researchRun = $this->runForWorkspace($workspace, $run);
        $data = $request->validate([
            'idempotency_key' => ['required_without:client_operation_id', 'nullable', 'string', 'max:160'],
            'client_operation_id' => ['required_without:idempotency_key', 'nullable', 'string', 'max:160'],
            'preflight_hash' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'maximum_cost_micros' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
        ]);
        $idempotencyKey = (string) ($data['idempotency_key'] ?? $data['client_operation_id'] ?? '');

        try {
            $updated = $this->refresh->handle(
                $workspace,
                $researchRun,
                $user,
                $idempotencyKey,
                $data['preflight_hash'] ?? null,
                isset($data['maximum_cost_micros']) ? (int) $data['maximum_cost_micros'] : null,
            );
        } catch (RuntimeException $exception) {
            return $this->mutationError($request, $researchRun->account_id, $exception, 'Research refresh could not be started.');
        }

        if ($request->expectsJson()) {
            return response()->json(['run' => $this->runPayload($updated)]);
        }

        return redirect()->route('accounts.intelligence.show', [
            'account' => $updated->account_id,
            'run' => $updated->id,
        ])->with('success', 'A fresh research run was queued.');
    }

    /** Append an owner-confirmed Claim successor; original evidence is intact. */
    public function correctClaim(Request $request, string $claim): JsonResponse|RedirectResponse
    {
        [$user, $workspace] = $this->workspaceContext($request);
        $this->assertOwner($workspace, $user);
        $researchClaim = ResearchClaim::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($claim)
            ->firstOrFail();
        $data = $request->validate([
            'value' => ['present'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'value_type' => ['nullable', 'string', 'in:string,url,date,integer,number,boolean,string_list,object'],
        ]);

        try {
            $successor = $this->correctClaim->correct(
                $workspace,
                $researchClaim,
                $user,
                $data['value'],
                $data['reason'] ?? null,
                $data['value_type'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return $this->mutationError($request, $researchClaim->account_id, $exception, 'Claim correction could not be saved.');
        }

        if ($request->expectsJson()) {
            return response()->json(['claim' => $this->claimPayload($workspace, $successor)]);
        }

        return redirect()->route('accounts.intelligence.show', ['account' => $researchClaim->account_id])
            ->with('success', 'Owner correction saved as a new Claim version.');
    }

    /** Apply an explicit owner review action to the latest decision. */
    public function reviewQualification(Request $request, string $decision): JsonResponse|RedirectResponse
    {
        [$user, $workspace] = $this->workspaceContext($request);
        $this->assertOwner($workspace, $user);
        $qualificationDecision = QualificationDecision::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($decision)
            ->firstOrFail();
        $data = $request->validate([
            'action' => ['required', 'string', 'in:accept,correct,reject,request_research,defect'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'outcome' => ['nullable', 'string', 'in:contact,withhold,research_further,insufficient_evidence,needs_review'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'explanation' => ['nullable', 'string', 'max:4000'],
            'missing_facts' => ['sometimes', 'array', 'max:50'],
            'missing_facts.*' => ['string', 'max:160'],
        ]);

        $action = QualificationReviewAction::from((string) $data['action']);
        $correction = null;
        if ($action === QualificationReviewAction::Correct) {
            $correction = new QualificationEvaluation(
                outcome: QualificationOutcome::from(
                    (string) ($data['outcome'] ?? $this->enumValue($qualificationDecision->outcome) ?? QualificationOutcome::NeedsReview->value),
                ),
                confidence: isset($data['confidence']) ? (float) $data['confidence'] : (float) $qualificationDecision->confidence,
                calibrationBand: $qualificationDecision->calibration_band,
                missingFacts: $this->stringList($data['missing_facts'] ?? $qualificationDecision->missing_facts),
                disqualifiers: $this->stringList($qualificationDecision->disqualifiers),
                explanation: (string) ($data['explanation'] ?? $qualificationDecision->explanation),
                claims: $this->qualificationClaims($qualificationDecision),
            );
        }

        try {
            $updated = $this->reviewQualification->review(
                $workspace,
                $qualificationDecision,
                $user,
                $action,
                $data['reason'] ?? null,
                $correction,
            );
        } catch (RuntimeException $exception) {
            return $this->mutationError($request, $qualificationDecision->account_id, $exception, 'Qualification review could not be saved.');
        }

        if ($request->expectsJson()) {
            return response()->json(['qualification' => $this->decisionPayload($updated)]);
        }

        return redirect()->route('accounts.intelligence.show', ['account' => $qualificationDecision->account_id])
            ->with('success', 'Qualification review saved.');
    }

    /** Re-evaluate a linked Offer's persisted readiness projection. */
    public function evaluateOffer(Request $request, string $offer): JsonResponse|RedirectResponse
    {
        [$user, $workspace] = $this->workspaceContext($request);
        $this->assertOwner($workspace, $user);
        $model = $this->offerForWorkspace($workspace, $offer);
        $evaluation = $this->offerReadiness->handle($model, 'account_intelligence');

        if ($request->expectsJson()) {
            return response()->json(['readiness' => $this->readinessPayload($evaluation)]);
        }

        return redirect()->route('accounts.intelligence.show', [
            'account' => $this->accountForOffer($workspace, $model),
        ])->with('success', 'Offer readiness evaluated.');
    }

    /** Review an Offer Version from the focused intelligence surface. */
    public function reviewOffer(Request $request, string $offer): JsonResponse|RedirectResponse
    {
        [$user, $workspace] = $this->workspaceContext($request);
        $this->assertOwner($workspace, $user);
        $model = $this->offerForWorkspace($workspace, $offer);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:submit,approve,reject,reopen'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $this->reviewOffer->handle($model, $user, (string) $data['action'], $data['reason'] ?? null);
        } catch (RuntimeException $exception) {
            return $this->mutationError($request, $this->accountForOffer($workspace, $model), $exception, 'Offer review could not be saved.');
        }

        if ($request->expectsJson()) {
            return response()->json(['offer' => $this->offerModelPayload($updated)]);
        }

        return redirect()->route('accounts.intelligence.show', [
            'account' => $this->accountForOffer($workspace, $updated),
        ])->with('success', 'Offer review saved.');
    }

    /** @return array{0:User,1:Workspace,2:Account} */
    private function context(Request $request, int|string $account): array
    {
        [$user, $workspace] = $this->workspaceContext($request);
        $accountModel = Account::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($account)
            ->firstOrFail();
        $this->authorize('view', $accountModel);

        return [$user, $workspace, $accountModel];
    }

    /** @return array{0:User,1:Workspace} */
    private function workspaceContext(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $workspace = $user->currentWorkspace();
        abort_unless($workspace->membershipFor($user) !== null, 403);

        return [$user, $workspace];
    }

    private function assertOwner(Workspace $workspace, User $user): void
    {
        if (! $workspace->isOwner($user)) {
            throw new AuthorizationException('Only the workspace owner can change Account intelligence.');
        }
    }

    private function selectedRun(Request $request, Workspace $workspace, Account $account): ?AccountResearchRun
    {
        $runId = $request->query('run');
        if ($runId === null || $runId === '') {
            return AccountResearchRun::query()
                ->where('workspace_id', $workspace->id)
                ->where('account_id', $account->id)
                ->with('stages')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();
        }

        abort_unless(is_string($runId) && Str::isUuid($runId), 404);

        return $this->runForAccount($workspace, $account, $runId);
    }

    private function runForWorkspace(Workspace $workspace, string $run): AccountResearchRun
    {
        abort_unless(Str::isUuid($run), 404);

        return AccountResearchRun::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($run)
            ->with('stages')
            ->firstOrFail();
    }

    private function runForAccount(Workspace $workspace, Account $account, string $run): AccountResearchRun
    {
        abort_unless(Str::isUuid($run), 404);

        return AccountResearchRun::query()
            ->where('workspace_id', $workspace->id)
            ->where('account_id', $account->id)
            ->whereKey($run)
            ->with('stages')
            ->firstOrFail();
    }

    private function offerForWorkspace(Workspace $workspace, string $offer): Offer
    {
        abort_unless(Str::isUuid($offer), 404);

        return $workspace->offers()
            ->whereKey($offer)
            ->with(['currentVersion', 'currentReadinessEvaluation'])
            ->firstOrFail();
    }

    private function accountForOffer(Workspace $workspace, Offer $offer): int
    {
        $versionIds = $offer->versions()
            ->where('workspace_id', $workspace->id)
            ->pluck('id')
            ->all();

        return (int) AccountResearchRun::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('offer_version_id', $versionIds)
            ->orderByDesc('created_at')
            ->value('account_id');
    }

    /** @return array<string,mixed> */
    private function accountPayload(Account $account): array
    {
        $provenance = ProvenanceSummary::for($account);

        return [
            'id' => (int) $account->id,
            'name' => (string) $account->name,
            'website' => $account->website,
            'domain' => $account->domain_normalized,
            'notes' => $account->notes,
            'tags' => array_values($account->tags),
            'source' => (string) $account->source,
            'provenance' => $provenance,
            'supplied_by' => [
                'source' => $provenance['primary_source'],
                'source_count' => $provenance['source_count'],
                'last_observed_at' => $provenance['last_observed_at'],
                'has_conflicts' => $provenance['has_conflicts'],
                'has_restrictions' => $provenance['has_restrictions'],
                'omission_reason' => $provenance['source_omission_reason'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function runPayload(AccountResearchRun $run): array
    {
        $state = $this->enumValue($run->state) ?? (string) $run->getRawOriginal('state');
        $costs = $run->costProjection();
        $stages = $run->relationLoaded('stages')
            ? $run->stages
            : $run->stages()->orderBy('stage_order')->get();

        return [
            'id' => (string) $run->id,
            'workspace_id' => (int) $run->workspace_id,
            'account_id' => (int) $run->account_id,
            'parent_run_id' => $run->parent_run_id === null ? null : (string) $run->parent_run_id,
            'state' => $state,
            'state_label' => $this->runStateLabel($state),
            'awaiting_reason' => $run->awaiting_reason,
            'safe_failure_code' => $run->safe_failure_code,
            'current_stage' => $run->current_stage,
            'estimated_cost_micros' => (int) $run->estimated_cost_micros,
            'maximum_cost_micros' => (int) $run->maximum_cost_micros,
            'reserved_cost_micros' => $costs['reserved_cost_micros'],
            'actual_cost_micros' => $costs['actual_cost_micros'],
            'currency' => (string) $run->currency,
            'input_hash' => (string) $run->input_hash,
            'freshness_policy' => (array) $run->freshness_policy,
            'capability_snapshot' => (array) $run->capability_snapshot,
            'queued_at' => $this->isoDate($run->queued_at),
            'started_at' => $this->isoDate($run->started_at),
            'finished_at' => $this->isoDate($run->finished_at),
            'cancelled_at' => $this->isoDate($run->cancelled_at),
            'stages' => $stages->sortBy('stage_order')->values()->map(fn ($stage): array => [
                'id' => (string) $stage->id,
                'stage_key' => $this->enumValue($stage->stage_key) ?? (string) $stage->getRawOriginal('stage_key'),
                'stage_order' => (int) $stage->stage_order,
                'state' => $this->enumValue($stage->state) ?? (string) $stage->getRawOriginal('state'),
                'entry_predicate' => (array) ($stage->entry_predicate_snapshot ?? []),
                'missing_facts' => (array) ($stage->missing_facts_snapshot ?? []),
                'expected_cost_micros' => (int) $stage->expected_cost_micros,
                'maximum_cost_micros' => (int) $stage->maximum_cost_micros,
                'safe_error_code' => $stage->safe_error_code,
                'started_at' => $this->isoDate($stage->started_at),
                'succeeded_at' => $this->isoDate($stage->succeeded_at),
                'failed_at' => $this->isoDate($stage->failed_at),
            ])->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return list<array<string,mixed>>
     */
    private function claimsPayload(Workspace $workspace, array $projection): array
    {
        $projectedClaims = $this->claimProjection($projection['claims'] ?? null);
        if ($projectedClaims === []) {
            return [];
        }
        $ids = array_values(array_filter(array_map(
            static fn (array $claim): ?string => is_string($claim['claim_id'] ?? null) ? $claim['claim_id'] : null,
            $projectedClaims,
        )));
        $models = ResearchClaim::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $ids)
            ->with(['evidence.sourceSnapshot.providerOperation'])
            ->get()
            ->keyBy(fn (ResearchClaim $claim): string => (string) $claim->id);

        return array_map(function (array $claim) use ($models, $workspace): array {
            $model = $models->get((string) $claim['claim_id']);
            if (! $model instanceof ResearchClaim) {
                return [...$claim, 'evidence' => []];
            }

            return [
                ...$claim,
                'value' => $model->value_json,
                'evidence' => $model->evidence
                    ->map(fn ($edge): array => $this->evidencePayload($edge->sourceSnapshot, $edge->locator, $edge->edge_type, $edge->sourceSnapshot?->providerOperation))
                    ->values()
                    ->all(),
                'source_count' => $model->evidence->pluck('source_snapshot_id')->unique()->count(),
                'metadata' => [
                    'extractor_kind' => (string) $model->extractor_kind,
                    'extractor_version' => (string) $model->extractor_version,
                    'policy_version' => $model->policy_version,
                    'model_version' => $model->model_version,
                ],
                'history' => $this->claimHistory($workspace, $model),
            ];
        }, $projectedClaims);
    }

    /** @return list<array<string,mixed>> */
    private function claimHistory(Workspace $workspace, ResearchClaim $claim): array
    {
        $history = [];
        $priorId = $claim->supersedes_claim_id;

        for ($depth = 0; $depth < 20 && is_string($priorId) && $priorId !== ''; $depth++) {
            $prior = ResearchClaim::query()
                ->where('workspace_id', $workspace->id)
                ->where('account_id', $claim->account_id)
                ->whereKey($priorId)
                ->with('evidence.sourceSnapshot.providerOperation')
                ->first();
            if (! $prior instanceof ResearchClaim) {
                break;
            }

            $event = ResearchClaimEvent::query()
                ->where('workspace_id', $workspace->id)
                ->where('claim_id', $prior->id)
                ->where('successor_claim_id', $claim->id)
                ->latest('created_at')
                ->first();
            $history[] = [
                'claim_id' => (string) $prior->id,
                'predicate' => (string) $prior->predicate,
                'value' => $prior->value_json,
                'value_type' => (string) $prior->value_type,
                'status' => $this->enumValue($prior->status) ?? (string) $prior->getRawOriginal('status'),
                'confidence' => (float) $prior->confidence,
                'reason' => $event?->reason,
                'superseded_at' => $this->isoDate($event?->created_at),
                'evidence' => $prior->evidence
                    ->map(fn ($edge): array => $this->evidencePayload(
                        $edge->sourceSnapshot,
                        $edge->locator,
                        $edge->edge_type,
                        $edge->sourceSnapshot?->providerOperation,
                    ))
                    ->values()
                    ->all(),
            ];

            $claim = $prior;
            $priorId = $prior->supersedes_claim_id;
        }

        return $history;
    }

    /**
     * @param  list<array<string,mixed>>  $claims
     * @return array<string,list<array<string,mixed>>>
     */
    private function claimsByStatus(array $claims): array
    {
        $statuses = ['supported', 'inferred', 'conflicting', 'stale', 'unsupported', 'owner_confirmed', 'rejected'];
        /** @var array<string,list<array<string,mixed>>> $grouped */
        $grouped = [];
        foreach ($statuses as $status) {
            $grouped[$status] = array_values(array_filter($claims, static fn (array $claim): bool => ($claim['status'] ?? null) === $status));
        }

        return $grouped;
    }

    /**
     * @param  list<array<string,mixed>>  $claims
     * @return array<string,int>
     */
    private function claimStatusCounts(array $claims): array
    {
        /** @var array<string,int> $counts */
        $counts = [];
        foreach ($this->claimsByStatus($claims) as $status => $group) {
            $counts[$status] = count($group);
        }

        return $counts;
    }

    /** @return array<string,mixed> */
    private function claimPayload(Workspace $workspace, ResearchClaim $claim): array
    {
        $projection = $this->projection->handle($workspace, (int) $claim->account_id);
        $claims = $this->claimsPayload($workspace, $projection);

        foreach ($claims as $item) {
            if (($item['claim_id'] ?? null) === (string) $claim->id) {
                return $item;
            }
        }

        return [
            'claim_id' => (string) $claim->id,
            'predicate' => (string) $claim->predicate,
            'value' => $claim->value_json,
            'status' => $this->enumValue($claim->status) ?? (string) $claim->getRawOriginal('status'),
        ];
    }

    /** @return array<string,mixed> */
    private function evidencePayload(
        ?ResearchSourceSnapshot $snapshot,
        ?string $locator,
        mixed $edgeType,
        mixed $operation,
    ): array {
        return [
            'snapshot_id' => $snapshot === null ? null : (string) $snapshot->id,
            'edge_type' => $this->enumValue($edgeType) ?? (string) $edgeType,
            'locator' => $locator,
            'source_type' => $snapshot?->source_type,
            'url' => $snapshot?->canonical_public_url,
            'registrable_domain' => $snapshot?->registrable_domain,
            'state' => $snapshot === null ? null : ($this->enumValue($snapshot->state) ?? (string) $snapshot->getRawOriginal('state')),
            'observed_at' => $this->isoDate($snapshot?->observed_at),
            'retrieved_at' => $this->isoDate($snapshot?->retrieved_at),
            'expires_at' => $this->isoDate($snapshot?->expires_at),
            'display_classification' => $snapshot?->display_classification,
            'llm_use_classification' => $snapshot?->llm_use_classification,
            'export_classification' => $snapshot?->export_classification,
            'retrieval_policy_hash' => $snapshot?->retrieval_policy_hash,
            'provider_operation' => $operation === null ? null : [
                'id' => (string) $operation->id,
                'provider' => $this->enumValue($operation->provider) ?? (string) $operation->getRawOriginal('provider'),
                'task' => $this->enumValue($operation->task) ?? (string) $operation->getRawOriginal('task'),
                'operation' => $operation->operation,
                'status' => $this->enumValue($operation->status) ?? (string) $operation->getRawOriginal('status'),
                'reserved_cost_micros' => (int) $operation->reserved_cost_micros,
                'actual_cost_micros' => (int) $operation->actual_cost_micros,
                'provider_request_id' => $operation->provider_request_id,
                'safe_error_code' => $operation->safe_error_code,
            ],
        ];
    }

    private function decision(Workspace $workspace, Account $account, ?AccountResearchRun $run): ?QualificationDecision
    {
        $query = QualificationDecision::query()
            ->where('workspace_id', $workspace->id)
            ->where('account_id', $account->id)
            ->with([
                'rubricVersion',
                'claimLinks',
                'reasonsToContact.claimLinks',
                'reviewEvents',
            ])
            ->orderByDesc('generated_at')
            ->orderByDesc('created_at');

        if ($run !== null) {
            $query->where('research_run_id', $run->id);
        }

        return $query->first();
    }

    /** @return array<string,mixed> */
    private function decisionPayload(QualificationDecision $decision): array
    {
        $rubric = $decision->rubricVersion;

        return [
            'id' => (string) $decision->id,
            'account_id' => (int) $decision->account_id,
            'research_run_id' => (string) $decision->research_run_id,
            'outcome' => $this->enumValue($decision->outcome) ?? (string) $decision->getRawOriginal('outcome'),
            'review_state' => $this->enumValue($decision->review_state) ?? (string) $decision->getRawOriginal('review_state'),
            'confidence' => (float) $decision->confidence,
            'calibration_band' => $decision->calibration_band,
            'missing_facts' => $this->stringList($decision->missing_facts),
            'disqualifiers' => $this->stringList($decision->disqualifiers),
            'explanation' => $decision->explanation,
            'provider_cost_micros' => (int) $decision->provider_cost_micros,
            'generated_at' => $this->isoDate($decision->generated_at),
            'reviewed_at' => $this->isoDate($decision->reviewed_at),
            'rubric' => $rubric === null ? null : [
                'id' => (string) $rubric->id,
                'version' => (int) $rubric->version,
                'dimensions' => $rubric->dimensions,
                'required_facts' => $rubric->required_facts,
                'disqualifiers' => $rubric->disqualifiers,
                'decision_rules' => $rubric->decision_rules,
            ],
            'claims' => $this->qualificationClaims($decision),
            'reasons_to_contact' => $decision->reasonsToContact->map(fn (ReasonToContactVersion $reason): array => [
                'id' => (string) $reason->id,
                'why_account' => $reason->why_account,
                'why_offer' => $reason->why_offer,
                'why_now' => $reason->why_now,
                'factual_basis' => $reason->factual_basis,
                'internal_rationale' => $reason->internal_rationale,
                'confidence' => (float) $reason->confidence,
                'safe_for_message' => (bool) $reason->safe_for_message,
                'visibility_label' => $reason->safe_for_message ? 'Approved for message use' : 'Internal only — not approved for messaging',
                'sensitive_inference' => (bool) $reason->sensitive_inference,
                'claim_ids' => $reason->claimLinks->pluck('claim_id')->map(static fn ($id): string => (string) $id)->values()->all(),
            ])->values()->all(),
            'review_events' => $decision->reviewEvents->sortByDesc('created_at')->values()->map(fn (QualificationReviewEvent $event): array => [
                'id' => (string) $event->id,
                'action' => $this->enumValue($event->action) ?? (string) $event->getRawOriginal('action'),
                'reason' => $event->reason,
                'prior_review_state' => $event->prior_review_state,
                'projected_review_state' => $event->projected_review_state,
                'created_at' => $this->isoDate($event->created_at),
                'actor' => $this->arrayOrNull($event->actor_snapshot),
            ])->all(),
        ];
    }

    /** @return array<string,mixed>|null */
    private function offerPayload(Workspace $workspace, ?AccountResearchRun $run): ?array
    {
        if ($run?->offer_version_id === null) {
            return null;
        }
        $version = OfferVersion::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($run->offer_version_id)
            ->first();
        if ($version === null) {
            return null;
        }
        $offer = Offer::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($version->offer_id)
            ->with('currentReadinessEvaluation')
            ->first();
        if ($offer === null) {
            return null;
        }

        $evaluation = OfferReadinessEvaluation::query()
            ->where('workspace_id', $workspace->id)
            ->where('offer_id', $offer->id)
            ->where('offer_version_id', $version->id)
            ->orderByDesc('evaluated_at')
            ->first();

        return [
            ...$this->offerModelPayload($offer),
            'linked_version_id' => (string) $version->id,
            'linked_version' => $this->offerVersionPayload($version),
            'readiness' => $evaluation === null ? null : $this->readinessPayload($evaluation),
        ];
    }

    /** @return array<string,mixed> */
    private function offerModelPayload(Offer $offer): array
    {
        $version = $offer->relationLoaded('currentVersion') ? $offer->currentVersion : $offer->currentVersion()->first();
        $payload = is_array($version?->payload) ? $version->payload : [];

        return [
            'id' => (string) $offer->id,
            'status' => $this->enumValue($offer->status) ?? (string) $offer->getRawOriginal('status'),
            'availability_state' => $this->enumValue($offer->availability_state) ?? (string) $offer->getRawOriginal('availability_state'),
            'lock_version' => (int) $offer->lock_version,
            'current_version_id' => $offer->current_version_id === null ? null : (string) $offer->current_version_id,
            'summary' => [
                'name' => $this->scalar($payload['name'] ?? null),
                'promise' => $this->scalar($payload['promise'] ?? data_get($payload, 'proposition.promise')),
                'target_audience' => $this->scalar($payload['target_audience'] ?? null),
                'call_to_action' => $this->scalar($payload['call_to_action'] ?? data_get($payload, 'cta.label')),
                'fulfilment_mode' => $this->scalar(data_get($payload, 'fulfilment.mode')),
                'destination_url' => $this->scalar(data_get($payload, 'cta.destination_url')),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function offerVersionPayload(OfferVersion $version): array
    {
        $payload = $version->payload;

        return [
            'id' => (string) $version->id,
            'version' => (int) $version->version,
            'schema_version' => (int) ($version->schema_version ?? 1),
            'content_hash' => (string) $version->content_hash,
            'summary' => [
                'name' => $this->scalar($payload['name'] ?? null),
                'promise' => $this->scalar($payload['promise'] ?? data_get($payload, 'proposition.promise')),
                'target_audience' => $this->scalar($payload['target_audience'] ?? null),
                'call_to_action' => $this->scalar($payload['call_to_action'] ?? data_get($payload, 'cta.label')),
                'fulfilment_mode' => $this->scalar(data_get($payload, 'fulfilment.mode')),
                'destination_url' => $this->scalar(data_get($payload, 'cta.destination_url')),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function readinessPayload(OfferReadinessEvaluation $evaluation): array
    {
        return [
            'id' => (string) $evaluation->id,
            'offer_id' => (string) $evaluation->offer_id,
            'offer_version_id' => (string) $evaluation->offer_version_id,
            'state' => $this->enumValue($evaluation->state) ?? (string) $evaluation->getRawOriginal('state'),
            'blockers' => $this->readinessBlockers($evaluation->blockers),
            'evaluated_at' => $this->isoDate($evaluation->evaluated_at),
            'valid_until' => $this->isoDate($evaluation->valid_until),
        ];
    }

    /** @return array<string,mixed> */
    private function conversionPayload(Workspace $workspace, Account $account): array
    {
        $definitions = ConversionDefinition::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->with('currentVersion')
            ->orderBy('event_key')
            ->limit(20)
            ->get()
            ->map(fn (ConversionDefinition $definition): array => [
                'id' => (string) $definition->id,
                'event_key' => (string) $definition->event_key,
                'status' => $this->enumValue($definition->status) ?? (string) $definition->getRawOriginal('status'),
                'current_version' => $definition->currentVersion === null ? null : [
                    'id' => (string) $definition->currentVersion->id,
                    'version' => (int) $definition->currentVersion->version,
                    'display_name' => $definition->currentVersion->display_name,
                    'description' => $definition->currentVersion->description,
                    'subject_type' => $this->enumValue($definition->currentVersion->subject_type) ?? (string) $definition->currentVersion->subject_type,
                ],
            ])->values()->all();
        $events = ConversionEvent::query()
            ->where('workspace_id', $workspace->id)
            ->where('matched_account_id', $account->id)
            ->with(['definition', 'definitionVersion', 'attributionAssessments'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (ConversionEvent $event): array => [
                'id' => (string) $event->id,
                'event_key' => $event->definition?->event_key,
                'state' => $this->enumValue($event->state) ?? (string) $event->getRawOriginal('state'),
                'verification_state' => $this->enumValue($event->verification_state) ?? (string) $event->getRawOriginal('verification_state'),
                'occurred_at' => $this->isoDate($event->occurred_at),
                'received_at' => $this->isoDate($event->received_at),
                'attribution_state' => $event->attribution_state,
            ])->values()->all();

        $attributionState = collect($events)->pluck('attribution_state')->first(
            static fn (string $state): bool => $state !== 'not_evaluated',
        ) ?? 'not_evaluated';

        return [
            'definitions' => $definitions,
            'events' => $events,
            'event_count' => count($events),
            'attribution_state' => $attributionState,
        ];
    }

    /** @return array<string,mixed> */
    private function validatedResearchData(Request $request, bool $allowIdempotency): array
    {
        $rules = [
            'gtm_profile_version_id' => ['nullable', 'string', 'max:80'],
            'offer_version_id' => ['nullable', 'string', 'max:80'],
            'freshness_policy' => ['sometimes', 'array', 'max:5'],
            'freshness_policy.snapshot_max_age_hours' => ['sometimes', 'integer', 'min:1', 'max:8760'],
            'freshness_policy.claim_max_age_hours' => ['sometimes', 'integer', 'min:1', 'max:8760'],
            'capabilities' => ['sometimes', 'array', 'max:3'],
            'capabilities.*' => ['string', 'in:research.extract,research.search,llm.structured_generate'],
            'maximum_cost_micros' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'preflight_hash' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
        if ($allowIdempotency) {
            $rules['idempotency_key'] = ['required_without:client_operation_id', 'nullable', 'string', 'max:160'];
            $rules['client_operation_id'] = ['required_without:idempotency_key', 'nullable', 'string', 'max:160'];
        }

        return $request->validate($rules);
    }

    private function mutationError(Request $request, int $accountId, RuntimeException $exception, string $fallback): JsonResponse|RedirectResponse
    {
        $message = $exception instanceof AccountResearchPaused
            ? $exception->getMessage()
            : match ($exception->getMessage()) {
                'stale_version' => 'The saved intelligence changed elsewhere. Reload and try again.',
                'operation_replay_mismatch' => 'This operation key was already used for another change.',
                'The preflight inputs have changed; request a new preflight.' => 'The preflight is stale. Reload this Account and try again.',
                default => $fallback,
            };
        if ($request->expectsJson()) {
            return response()->json([
                'error' => [
                    'type' => $exception instanceof AccountResearchPaused
                        ? 'account_research_paused'
                        : 'intelligence_mutation_failed',
                    'message' => $message,
                    'code' => $exception->getMessage(),
                ],
            ], $exception instanceof AccountResearchPaused ? 503 : 422);
        }

        return back()->withErrors(['intelligence' => $message]);
    }

    /** @return array<string,mixed> */
    private function associativeArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function claimProjection(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $claims = [];
        foreach ($value as $claim) {
            if (is_array($claim)) {
                $claims[] = $claim;
            }
        }

        return $claims;
    }

    /** @return list<array{code:string,message:string}> */
    private function readinessBlockers(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $blockers = [];
        foreach ($value as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }
            $code = $blocker['code'] ?? null;
            $message = $blocker['message'] ?? null;
            if (is_string($code) && is_string($message)) {
                $blockers[] = ['code' => $code, 'message' => $message];
            }
        }

        return $blockers;
    }

    /** @return list<array{claim_id:string,role:string}> */
    private function qualificationClaims(QualificationDecision $decision): array
    {
        $claims = [];
        foreach ($decision->claimLinks as $link) {
            $claims[] = [
                'claim_id' => (string) $link->claim_id,
                'role' => $this->enumValue($link->role) ?? (string) $link->getRawOriginal('role'),
            ];
        }

        return $claims;
    }

    /** @return array<string,mixed>|null */
    private function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private function runStateLabel(string $state): string
    {
        return match ($state) {
            'awaiting_provider' => 'Waiting for provider setup',
            'awaiting_budget' => 'Waiting for workspace budget',
            'planned' => 'Queued to start',
            'running' => 'Research in progress',
            'paused' => 'Paused safely',
            'cancelling' => 'Stopping safely',
            'cancelled' => 'Cancelled; evidence retained',
            'partial' => 'Partial result; review needed',
            'completed' => 'Completed',
            'failed' => 'Needs attention',
            default => str($state)->replace('_', ' ')->title()->toString(),
        };
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function isoDate(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : null;
    }

    private function scalar(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
