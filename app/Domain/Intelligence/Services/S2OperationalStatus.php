<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Conversions\Enums\ConversionConnectionStatus;
use App\Domain\Conversions\Enums\ConversionCredentialStatus;
use App\Domain\Conversions\Enums\ConversionEventState;
use App\Domain\Conversions\Enums\ConversionIntakeState;
use App\Domain\Conversions\Enums\ConversionMatchResult;
use App\Domain\Conversions\Enums\ConversionReviewAction;
use App\Domain\Conversions\Enums\ConversionVerificationState;
use App\Domain\Gtm\Enums\OfferReadinessState;
use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Integrations\Enums\IntegrationOperationStatus;
use App\Domain\Intelligence\Enums\AccountResearchAttemptState;
use App\Domain\Intelligence\Enums\AccountResearchRunState;
use App\Domain\Intelligence\Enums\AccountResearchStageState;
use App\Domain\Intelligence\Enums\QualificationOutcome;
use App\Domain\Intelligence\Enums\QualificationReviewAction;
use App\Domain\Intelligence\Enums\QualificationReviewState;
use App\Domain\Intelligence\Enums\ResearchClaimEventAction;
use App\Domain\Intelligence\Enums\ResearchClaimStatus;
use App\Domain\Intelligence\Enums\ResearchDispatchState;
use App\Domain\Intelligence\Enums\ResearchSourceSnapshotState;
use App\Models\AccountResearchAttempt;
use App\Models\AccountResearchDispatch;
use App\Models\AccountResearchRun;
use App\Models\AccountResearchStage;
use App\Models\AiBudgetEnvelope;
use App\Models\AiBudgetRun;
use App\Models\AiProviderCallAttempt;
use App\Models\AiProviderCredential;
use App\Models\AiProviderOperation;
use App\Models\AiUsageLedger;
use App\Models\ConversionEvent;
use App\Models\ConversionEventMatch;
use App\Models\ConversionEventReceipt;
use App\Models\ConversionEventReviewEvent;
use App\Models\ConversionSourceConnection;
use App\Models\ConversionSourceConnectionVersion;
use App\Models\ExternalIdentity;
use App\Models\ExternalOperation;
use App\Models\ExternalOperationAttempt;
use App\Models\FulfilmentCapability;
use App\Models\FulfilmentCapacityWindow;
use App\Models\FulfilmentRequest;
use App\Models\Offer;
use App\Models\OfferReadinessEvaluation;
use App\Models\QualificationDecision;
use App\Models\QualificationReviewEvent;
use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvent;
use App\Models\ResearchSourceSnapshot;
use App\Models\SourceObservation;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Build a bounded, workspace-scoped S2 operational report.
 *
 * This service is intentionally read-only. It reports fixed state/error
 * buckets and aggregate ages/counts only; it never returns source content,
 * URLs, domains, identities, prompts, claim text, secrets, or row IDs.
 */
final class S2OperationalStatus
{
    /** @var array<string, bool> */
    private array $tableCache = [];

    /**
     * Safe error values that may be useful to operators. Unknown values are
     * collapsed into the fixed `other` bucket rather than becoming labels.
     *
     * @var list<string>
     */
    private const SAFE_ERROR_CODES = [
        'account_ineligible',
        'budget_unavailable',
        'connection_disabled',
        'credential_decrypt_failed',
        'execution_lease_expired',
        'fence_rejected',
        'initiator_membership_lost',
        'invalid_credential',
        'model_unavailable',
        'output_invalid',
        'paid_result_lost',
        'pre_call_blocked',
        'provider_failed',
        'provider_uncertain',
        'provider_unavailable',
        'rate_limited',
        'recovered',
        'reconciliation_failed',
        'request_rejected',
        'retry_exhausted',
        'research_failed',
        'research_timed_out',
        'signature_invalid',
        'signature_replay',
        'stale_version',
        'uncertain_provider_charge',
        'unknown_provider_failure',
    ];

    /** @var list<string> */
    private const OFFER_BLOCKER_CODES = [
        'approved_claims_missing',
        'asset_version_missing',
        'asset_version_unavailable',
        'commercial_authority_missing',
        'conversion_definition_unavailable',
        'cta_destination_unavailable',
        'fulfilment_capability_unavailable',
        'fulfilment_mode_missing',
        'human_fulfilment_incomplete',
        'offer_expired',
        'offer_schema_incomplete',
    ];

    /** @var list<string> */
    private const FULFILMENT_REQUEST_STATES = [
        'pending',
        'queued',
        'in_progress',
        'fulfilled',
        'blocked',
        'failed',
        'cancelled',
    ];

    /** @var list<string> */
    private const CAPABILITY_STATES = ['draft', 'active', 'paused', 'disabled'];

    /** @var list<string> */
    private const CAPABILITY_HEALTH_STATES = ['healthy', 'degraded', 'unhealthy', 'unknown'];

    /** @var list<string> */
    private const RETENTION_SIGNATURE_CODES = [
        'signature_invalid',
        'signature_replay',
        'webhook_signature_invalid',
        'webhook_signature_replay',
    ];

    /**
     * @return array<string,mixed>
     */
    public function handle(?int $workspaceId = null, ?DateTimeInterface $at = null): array
    {
        return $this->report($workspaceId, $at);
    }

    /**
     * @return array<string,mixed>
     */
    public function report(?int $workspaceId = null, ?DateTimeInterface $at = null): array
    {
        $this->tableCache = [];
        $now = $at === null ? CarbonImmutable::now() : CarbonImmutable::instance($at);
        $metrics = [
            'research' => $this->researchMetrics($workspaceId, $now),
            'provider_operations' => $this->providerMetrics($workspaceId, $now),
            'budget' => $this->budgetMetrics($workspaceId, $now),
            'snapshots' => $this->snapshotMetrics($workspaceId, $now),
            'claims' => $this->claimMetrics($workspaceId),
            'qualification' => $this->qualificationMetrics($workspaceId),
            'offers' => $this->offerMetrics($workspaceId, $now),
            'fulfilment' => $this->fulfilmentMetrics($workspaceId, $now),
            'conversions' => $this->conversionMetrics($workspaceId, $now),
        ];
        $metrics['retention'] = $this->retentionMetrics($workspaceId, $now, $metrics);
        $alerts = $this->alerts($metrics);

        return [
            'schema_version' => 1,
            'generated_at' => $now->toIso8601String(),
            'scope' => ['workspace_id' => $workspaceId],
            'controls' => [
                'pause_new_research' => (bool) config('intelligence.research.pause_new', false),
            ],
            'metrics' => $metrics,
            'alerts' => $alerts,
            'alert_count' => count($alerts),
            'status' => $this->overallStatus($alerts),
        ];
    }

    /** @return array<string,mixed> */
    private function researchMetrics(?int $workspaceId, CarbonImmutable $now): array
    {
        $runStates = array_map(static fn (AccountResearchRunState $state): string => $state->value, AccountResearchRunState::cases());
        $stageStates = array_map(static fn (AccountResearchStageState $state): string => $state->value, AccountResearchStageState::cases());
        $attemptStates = array_map(static fn (AccountResearchAttemptState $state): string => $state->value, AccountResearchAttemptState::cases());
        $dispatchStates = array_map(static fn (ResearchDispatchState $state): string => $state->value, ResearchDispatchState::cases());

        $runDates = [];
        foreach ($runStates as $state) {
            $runDates[$state] = [
                'primary' => in_array($state, [
                    AccountResearchRunState::Running->value,
                    AccountResearchRunState::Completed->value,
                    AccountResearchRunState::Partial->value,
                    AccountResearchRunState::Failed->value,
                    AccountResearchRunState::Cancelled->value,
                ], true) ? 'started_at' : 'queued_at',
                'fallback' => 'created_at',
            ];
        }
        foreach ([AccountResearchRunState::Completed->value, AccountResearchRunState::Partial->value, AccountResearchRunState::Failed->value, AccountResearchRunState::Cancelled->value] as $state) {
            $runDates[$state] = ['primary' => 'finished_at', 'fallback' => 'created_at'];
        }

        $stageDates = [];
        foreach ($stageStates as $state) {
            $stageDates[$state] = ['primary' => 'started_at', 'fallback' => 'created_at'];
        }
        $attemptDates = [];
        foreach ($attemptStates as $state) {
            $attemptDates[$state] = ['primary' => 'started_at', 'fallback' => 'created_at'];
        }
        $dispatchDates = [];
        foreach ($dispatchStates as $state) {
            $dispatchDates[$state] = ['primary' => 'created_at', 'fallback' => 'available_at'];
        }

        $runAge = $this->stateAges(AccountResearchRun::class, $workspaceId, 'state', $runDates, $now);
        $stageAge = $this->stateAges(AccountResearchStage::class, $workspaceId, 'state', $stageDates, $now);
        $attemptAge = $this->stateAges(AccountResearchAttempt::class, $workspaceId, 'state', $attemptDates, $now);
        $dispatchAge = $this->stateAges(AccountResearchDispatch::class, $workspaceId, 'state', $dispatchDates, $now);
        $cutoff = $now->subSeconds($this->threshold('stuck_age_seconds', 900));

        $stuckRuns = $this->staleByState(AccountResearchRun::class, $workspaceId, 'state', [
            AccountResearchRunState::Planned->value,
            AccountResearchRunState::AwaitingProvider->value,
            AccountResearchRunState::AwaitingBudget->value,
            AccountResearchRunState::Running->value,
            AccountResearchRunState::Cancelling->value,
        ], ['primary' => 'started_at', 'fallback' => 'queued_at'], $cutoff);
        $stuckStages = $this->staleByState(AccountResearchStage::class, $workspaceId, 'state', [AccountResearchStageState::Running->value], ['primary' => 'started_at', 'fallback' => 'created_at'], $cutoff);
        $stuckDispatches = $this->staleByState(AccountResearchDispatch::class, $workspaceId, 'state', [ResearchDispatchState::Pending->value, ResearchDispatchState::Leased->value], ['primary' => 'created_at', 'fallback' => 'available_at'], $cutoff);

        $stageErrors = $this->errorCounts(AccountResearchStage::class, $workspaceId, 'safe_error_code');
        $attemptErrors = $this->errorCounts(AccountResearchAttempt::class, $workspaceId, 'safe_error_code');
        $runErrors = $this->errorCounts(AccountResearchRun::class, $workspaceId, 'safe_failure_code');
        $paidResultLoss = [
            'stage_count' => $stageErrors['paid_result_lost'] ?? 0,
            'attempt_count' => $attemptErrors['paid_result_lost'] ?? 0,
            'run_count' => $runErrors['paid_result_lost'] ?? 0,
            'count' => max(
                $stageErrors['paid_result_lost'] ?? 0,
                $attemptErrors['paid_result_lost'] ?? 0,
                $runErrors['paid_result_lost'] ?? 0,
            ),
        ];
        $outputInvalid = [
            'stage_count' => $stageErrors['output_invalid'] ?? 0,
            'attempt_count' => $attemptErrors['output_invalid'] ?? 0,
            'run_count' => $runErrors['output_invalid'] ?? 0,
            'count' => max(
                $stageErrors['output_invalid'] ?? 0,
                $attemptErrors['output_invalid'] ?? 0,
                $runErrors['output_invalid'] ?? 0,
            ),
        ];

        return [
            'runs' => [
                'count' => $this->scopedCount(AccountResearchRun::class, $workspaceId),
                'by_state' => $this->groupedCounts(AccountResearchRun::class, $workspaceId, 'state', $runStates),
                'ages_seconds_by_state' => $runAge,
                'oldest_age_seconds' => $this->oldestAge($runAge),
                'stuck_count' => $stuckRuns,
            ],
            'stages' => [
                'count' => $this->scopedCount(AccountResearchStage::class, $workspaceId),
                'by_state' => $this->groupedCounts(AccountResearchStage::class, $workspaceId, 'state', $stageStates),
                'ages_seconds_by_state' => $stageAge,
                'oldest_age_seconds' => $this->oldestAge($stageAge),
                'stuck_count' => $stuckStages,
                'paid_result_loss' => $paidResultLoss,
                'output_invalid' => $outputInvalid,
            ],
            'attempts' => [
                'count' => $this->scopedCount(AccountResearchAttempt::class, $workspaceId),
                'by_state' => $this->groupedCounts(AccountResearchAttempt::class, $workspaceId, 'state', $attemptStates),
                'ages_seconds_by_state' => $attemptAge,
                'oldest_age_seconds' => $this->oldestAge($attemptAge),
                'errors' => $attemptErrors,
            ],
            'dispatches' => [
                'count' => $this->scopedCount(AccountResearchDispatch::class, $workspaceId),
                'by_state' => $this->groupedCounts(AccountResearchDispatch::class, $workspaceId, 'state', $dispatchStates),
                'ages_seconds_by_state' => $dispatchAge,
                'oldest_age_seconds' => $this->oldestAge($dispatchAge),
                'stuck_count' => $stuckDispatches,
                'recovered_count' => $this->codeCount(AccountResearchDispatch::class, $workspaceId, 'safe_error_code', 'recovered'),
                'fence_rejection_count' => $this->codeCount(AccountResearchDispatch::class, $workspaceId, 'safe_error_code', 'fence_rejected'),
            ],
            'stuck_count' => $stuckRuns + $stuckStages + $stuckDispatches,
            'fence_rejection_count' => $this->fenceCount(AccountResearchAttempt::class, $workspaceId, 'safe_error_code'),
            'recovered_count' => $this->codeCount(AccountResearchDispatch::class, $workspaceId, 'safe_error_code', 'recovered'),
            'paid_result_loss' => $paidResultLoss,
            'output_invalid' => $outputInvalid,
        ];
    }

    /** @return array<string,mixed> */
    private function providerMetrics(?int $workspaceId, CarbonImmutable $now): array
    {
        $aiStates = array_map(static fn (AiOperationStatus $state): string => $state->value, AiOperationStatus::cases());
        $externalStates = array_map(static fn (IntegrationOperationStatus $state): string => $state->value, IntegrationOperationStatus::cases());
        $cutoff = $now->subSeconds($this->threshold('stuck_age_seconds', 900));
        $aiErrors = $this->errorCounts(AiProviderOperation::class, $workspaceId, 'safe_error_code');
        $externalErrors = $this->errorCounts(ExternalOperation::class, $workspaceId, 'safe_error_code');
        $aiLatency = $this->latencyMetrics(AiProviderCallAttempt::class, $workspaceId, 'operation');
        $externalLatency = $this->latencyMetrics(ExternalOperationAttempt::class, $workspaceId, 'operation');
        $aiResultCount = $this->scoped(AiProviderOperation::class, $workspaceId)
            ->whereIn('status', [AiOperationStatus::Succeeded->value, AiOperationStatus::Reconciled->value, AiOperationStatus::Failed->value, AiOperationStatus::ManualReview->value])
            ->count();
        $externalResultCount = $this->scoped(ExternalOperation::class, $workspaceId)
            ->whereIn('status', [IntegrationOperationStatus::Succeeded->value, IntegrationOperationStatus::Reconciled->value, IntegrationOperationStatus::Failed->value, IntegrationOperationStatus::ManualReview->value])
            ->count();
        $providerInvalidCount = max($aiErrors['output_invalid'] ?? 0, $externalErrors['output_invalid'] ?? 0);
        $providerResultCount = $aiResultCount + $externalResultCount;

        return [
            'ai' => [
                'count' => $this->scopedCount(AiProviderOperation::class, $workspaceId),
                'by_status' => $this->groupedCounts(AiProviderOperation::class, $workspaceId, 'status', $aiStates),
                'ages_seconds_by_status' => $this->stateAges(AiProviderOperation::class, $workspaceId, 'status', array_fill_keys($aiStates, ['primary' => 'updated_at', 'fallback' => 'created_at']), $now),
                'oldest_age_seconds' => $this->oldestAge($this->stateAges(AiProviderOperation::class, $workspaceId, 'status', array_fill_keys($aiStates, ['primary' => 'updated_at', 'fallback' => 'created_at']), $now)),
                'stuck_count' => $this->staleByState(AiProviderOperation::class, $workspaceId, 'status', [AiOperationStatus::Executing->value, AiOperationStatus::Uncertain->value, AiOperationStatus::Reconciling->value], ['primary' => 'updated_at', 'fallback' => 'started_at'], $cutoff),
                'uncertain_count' => $this->scoped(AiProviderOperation::class, $workspaceId)->whereIn('status', [AiOperationStatus::Uncertain->value, AiOperationStatus::Reconciling->value])->count(),
                'manual_review_count' => $this->scoped(AiProviderOperation::class, $workspaceId)->where('status', AiOperationStatus::ManualReview->value)->count(),
                'paid_result_loss_count' => $aiErrors['paid_result_lost'] ?? 0,
                'output_invalid_count' => $aiErrors['output_invalid'] ?? 0,
                'errors' => $aiErrors,
                'latency' => $aiLatency,
                'reserved_cost_micros' => (int) $this->scoped(AiProviderOperation::class, $workspaceId)->sum('reserved_cost_micros'),
                'actual_cost_micros' => (int) $this->scoped(AiProviderOperation::class, $workspaceId)->whereNotNull('actual_cost_micros')->sum('actual_cost_micros'),
                'fence_rejection_count' => $this->fenceCount(AiProviderOperation::class, $workspaceId, 'safe_error_code'),
            ],
            'integrations' => [
                'count' => $this->scopedCount(ExternalOperation::class, $workspaceId),
                'by_status' => $this->groupedCounts(ExternalOperation::class, $workspaceId, 'status', $externalStates),
                'ages_seconds_by_status' => $this->stateAges(ExternalOperation::class, $workspaceId, 'status', array_fill_keys($externalStates, ['primary' => 'updated_at', 'fallback' => 'created_at']), $now),
                'oldest_age_seconds' => $this->oldestAge($this->stateAges(ExternalOperation::class, $workspaceId, 'status', array_fill_keys($externalStates, ['primary' => 'updated_at', 'fallback' => 'created_at']), $now)),
                'stuck_count' => $this->staleByState(ExternalOperation::class, $workspaceId, 'status', [IntegrationOperationStatus::Executing->value, IntegrationOperationStatus::Uncertain->value, IntegrationOperationStatus::Ambiguous->value, IntegrationOperationStatus::Reconciling->value, IntegrationOperationStatus::ReconciliationPending->value], ['primary' => 'updated_at', 'fallback' => 'started_at'], $cutoff),
                'uncertain_count' => $this->scoped(ExternalOperation::class, $workspaceId)->whereIn('status', [IntegrationOperationStatus::Uncertain->value, IntegrationOperationStatus::Ambiguous->value, IntegrationOperationStatus::Reconciling->value, IntegrationOperationStatus::ReconciliationPending->value])->count(),
                'manual_review_count' => $this->scoped(ExternalOperation::class, $workspaceId)->where('status', IntegrationOperationStatus::ManualReview->value)->count(),
                'paid_result_loss_count' => $externalErrors['paid_result_lost'] ?? 0,
                'output_invalid_count' => $externalErrors['output_invalid'] ?? 0,
                'errors' => $externalErrors,
                'latency' => $externalLatency,
                'reserved_cost_micros' => (int) $this->scoped(ExternalOperation::class, $workspaceId)->sum('reserved_cost_micros'),
                'actual_cost_micros' => (int) $this->scoped(ExternalOperation::class, $workspaceId)->whereNotNull('actual_cost_micros')->sum('actual_cost_micros'),
                'fence_rejection_count' => $this->fenceCount(ExternalOperation::class, $workspaceId, 'safe_error_code'),
            ],
            'credentials' => [
                'by_status' => $this->groupedCounts(AiProviderCredential::class, $workspaceId, 'status', array_map(static fn (AiCredentialStatus $status): string => $status->value, AiCredentialStatus::cases())),
                'by_provider' => $this->groupedCounts(AiProviderCredential::class, $workspaceId, 'provider', array_map(static fn (AiProviderName $provider): string => $provider->value, AiProviderName::cases())),
            ],
            'result_count' => $providerResultCount,
            'output_invalid_count' => $providerInvalidCount,
            'output_invalid_rate' => $providerResultCount > 0 ? round($providerInvalidCount / $providerResultCount, 6) : 0.0,
            'paid_result_loss_count' => max($aiErrors['paid_result_lost'] ?? 0, $externalErrors['paid_result_lost'] ?? 0),
            'stuck_count' => $this->staleByState(AiProviderOperation::class, $workspaceId, 'status', [AiOperationStatus::Executing->value, AiOperationStatus::Uncertain->value, AiOperationStatus::Reconciling->value], ['primary' => 'updated_at', 'fallback' => 'started_at'], $cutoff)
                + $this->staleByState(ExternalOperation::class, $workspaceId, 'status', [IntegrationOperationStatus::Executing->value, IntegrationOperationStatus::Uncertain->value, IntegrationOperationStatus::Ambiguous->value, IntegrationOperationStatus::Reconciling->value, IntegrationOperationStatus::ReconciliationPending->value], ['primary' => 'updated_at', 'fallback' => 'started_at'], $cutoff),
            'fence_rejection_count' => $this->fenceCount(AiProviderOperation::class, $workspaceId, 'safe_error_code') + $this->fenceCount(ExternalOperation::class, $workspaceId, 'safe_error_code'),
            'cost_evidence' => $this->costEvidenceMetrics($workspaceId),
        ];
    }

    /** @return array<string,mixed> */
    private function budgetMetrics(?int $workspaceId, CarbonImmutable $now): array
    {
        $heldEnvelope = (int) $this->scoped(AiBudgetEnvelope::class, $workspaceId)->where('state', 'open')->where('held_micros', '>', 0)->sum('held_micros');
        $heldRun = (int) $this->scoped(AiBudgetRun::class, $workspaceId)->whereNull('closed_at')->where('held_micros', '>', 0)->sum('held_micros');
        $envelopeAges = $this->scoped(AiBudgetEnvelope::class, $workspaceId)->where('state', 'open')->where('held_micros', '>', 0)->whereNotNull('opened_at')->min('opened_at');
        $runAges = $this->scoped(AiBudgetRun::class, $workspaceId)->whereNull('closed_at')->where('held_micros', '>', 0)->whereNotNull('opened_at')->min('opened_at');
        $oldest = $this->oldestTimestamp([$envelopeAges, $runAges], $now);
        $ratio = $this->configFloat('cost_near_hard_maximum_ratio', 0.90);
        $nearMaximum = $this->scoped(AiBudgetEnvelope::class, $workspaceId)
            ->where('hard_limit_micros', '>', 0)
            ->whereRaw('(held_micros + committed_micros) >= hard_limit_micros * ?', [$ratio])
            ->count();

        return [
            'held_micros' => $heldEnvelope + $heldRun,
            'envelope_held_micros' => $heldEnvelope,
            'run_held_micros' => $heldRun,
            'open_hold_count' => $this->scoped(AiBudgetEnvelope::class, $workspaceId)->where('state', 'open')->where('held_micros', '>', 0)->count(),
            'oldest_hold_age_seconds' => $oldest,
            'near_hard_maximum_count' => $nearMaximum,
            'near_hard_maximum_ratio' => $ratio,
        ];
    }

    /** @return array<string,mixed> */
    private function snapshotMetrics(?int $workspaceId, CarbonImmutable $now): array
    {
        $states = array_map(static fn (ResearchSourceSnapshotState $state): string => $state->value, ResearchSourceSnapshotState::cases());
        $expired = $this->scoped(ResearchSourceSnapshot::class, $workspaceId)
            ->whereNotIn('state', [ResearchSourceSnapshotState::Expired->value, ResearchSourceSnapshotState::Redacted->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->count();

        return [
            'count' => $this->scopedCount(ResearchSourceSnapshot::class, $workspaceId),
            'by_state' => $this->groupedCounts(ResearchSourceSnapshot::class, $workspaceId, 'state', $states),
            'expiry_backlog_count' => $expired,
            'oldest_expiry_age_seconds' => $this->oldestAgeFromQuery($this->scoped(ResearchSourceSnapshot::class, $workspaceId)->whereNotIn('state', [ResearchSourceSnapshotState::Expired->value, ResearchSourceSnapshotState::Redacted->value])->whereNotNull('expires_at')->where('expires_at', '<=', $now), 'expires_at', $now),
        ];
    }

    /** @return array<string,mixed> */
    private function claimMetrics(?int $workspaceId): array
    {
        $states = array_map(static fn (ResearchClaimStatus $state): string => $state->value, ResearchClaimStatus::cases());
        $actions = array_map(static fn (ResearchClaimEventAction $action): string => $action->value, ResearchClaimEventAction::cases());
        $eventCounts = $this->groupedCounts(ResearchClaimEvent::class, $workspaceId, 'action', $actions);

        return [
            'count' => $this->scopedCount(ResearchClaim::class, $workspaceId),
            'by_status' => $this->groupedCounts(ResearchClaim::class, $workspaceId, 'status', $states),
            'conflict_count' => $this->scoped(ResearchClaim::class, $workspaceId)->where('status', ResearchClaimStatus::Conflicting->value)->count(),
            'correction_count' => $eventCounts[ResearchClaimEventAction::Correct->value] ?? 0,
            'event_count' => $this->scopedCount(ResearchClaimEvent::class, $workspaceId),
            'events_by_action' => $eventCounts,
        ];
    }

    /** @return array<string,mixed> */
    private function qualificationMetrics(?int $workspaceId): array
    {
        $outcomes = array_map(static fn (QualificationOutcome $outcome): string => $outcome->value, QualificationOutcome::cases());
        $reviews = array_map(static fn (QualificationReviewState $state): string => $state->value, QualificationReviewState::cases());
        $actions = array_map(static fn (QualificationReviewAction $action): string => $action->value, QualificationReviewAction::cases());
        $reviewEvents = $this->groupedCounts(QualificationReviewEvent::class, $workspaceId, 'action', $actions);
        $missing = $this->boundedJsonArrayMetric(QualificationDecision::class, $workspaceId, 'missing_facts');

        return [
            'count' => $this->scopedCount(QualificationDecision::class, $workspaceId),
            'by_outcome' => $this->groupedCounts(QualificationDecision::class, $workspaceId, 'outcome', $outcomes),
            'by_review_state' => $this->groupedCounts(QualificationDecision::class, $workspaceId, 'review_state', $reviews),
            'review_event_count' => $this->scopedCount(QualificationReviewEvent::class, $workspaceId),
            'reviews_by_action' => $reviewEvents,
            'missing_fact_count' => $missing['item_count'],
            'missing_fact_decision_count' => $missing['row_count'],
            'missing_fact_metric_truncated' => $missing['truncated'],
            'false_contact_defect_count' => $reviewEvents[QualificationReviewAction::Defect->value] ?? 0,
            'needs_review_count' => $this->scoped(QualificationDecision::class, $workspaceId)->where('outcome', QualificationOutcome::NeedsReview->value)->count(),
            'pending_review_count' => $this->scoped(QualificationDecision::class, $workspaceId)->where('review_state', QualificationReviewState::Pending->value)->count(),
        ];
    }

    /** @return array<string,mixed> */
    private function offerMetrics(?int $workspaceId, CarbonImmutable $now): array
    {
        $readinessStates = array_map(static fn (OfferReadinessState $state): string => $state->value, OfferReadinessState::cases());
        $offerStates = array_map(static fn (OfferStatus $state): string => $state->value, OfferStatus::cases());
        $blockers = array_fill_keys([...self::OFFER_BLOCKER_CODES, 'other'], 0);
        $evaluationIds = $this->scoped(Offer::class, $workspaceId)
            ->whereNotNull('current_readiness_evaluation_id')
            ->orderBy('id')
            ->limit($this->maxRows())
            ->pluck('current_readiness_evaluation_id')
            ->all();
        $evaluations = $this->scoped(OfferReadinessEvaluation::class, $workspaceId);
        if ($evaluationIds !== []) {
            $evaluations->whereIn('id', $evaluationIds);
        } else {
            $evaluations->whereNotNull('id')->latest('evaluated_at')->limit($this->maxRows());
        }
        $totalEvaluations = (clone $evaluations)->count();
        foreach ($evaluations->get(['id', 'blockers']) as $evaluation) {
            foreach ((array) $evaluation->getAttribute('blockers') as $blocker) {
                $code = is_array($blocker) ? (string) ($blocker['code'] ?? '') : '';
                if (array_key_exists($code, $blockers) && $code !== 'other') {
                    $blockers[$code]++;
                } elseif ($code !== '') {
                    $blockers['other']++;
                }
            }
        }
        $invalidated = $this->scoped(OfferReadinessEvaluation::class, $workspaceId)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', $now)
            ->count();
        $activeDependencyFailures = $this->scoped(Offer::class, $workspaceId)
            ->where('status', OfferStatus::Active->value)
            ->where('availability_state', '!=', OfferReadinessState::Approved->value)
            ->count();
        $offerAges = $this->stateAges(Offer::class, $workspaceId, 'availability_state', array_fill_keys($readinessStates, ['primary' => 'created_at', 'fallback' => 'updated_at']), $now);

        return [
            'count' => $this->scopedCount(Offer::class, $workspaceId),
            'by_readiness_state' => $this->groupedCounts(Offer::class, $workspaceId, 'availability_state', $readinessStates),
            'by_offer_status' => $this->groupedCounts(Offer::class, $workspaceId, 'status', $offerStates),
            'ages_seconds_by_readiness_state' => $offerAges,
            'oldest_age_seconds' => $this->oldestAge($offerAges),
            'blockers' => $blockers,
            'evaluation_count' => $totalEvaluations,
            'dependency_invalidated_count' => $invalidated,
            'active_dependency_failure_count' => $activeDependencyFailures,
            'blocker_metric_truncated' => $evaluationIds !== [] && count($evaluationIds) >= $this->maxRows(),
        ];
    }

    /** @return array<string,mixed> */
    private function fulfilmentMetrics(?int $workspaceId, CarbonImmutable $now): array
    {
        $requests = $this->groupedCounts(FulfilmentRequest::class, $workspaceId, 'state', self::FULFILMENT_REQUEST_STATES);
        $backlogStates = ['pending', 'queued', 'in_progress', 'blocked'];
        $backlogQuery = $this->scoped(FulfilmentRequest::class, $workspaceId)->whereIn('state', $backlogStates);
        $overdueQuery = $this->scoped(FulfilmentRequest::class, $workspaceId)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now)
            ->whereNotIn('state', ['fulfilled', 'failed', 'cancelled']);
        $windowQuery = $this->scoped(FulfilmentCapacityWindow::class, $workspaceId);
        $windows = [
            'count' => $windowQuery->count(),
            'limit_units' => (int) (clone $windowQuery)->sum('limit_units'),
            'reserved_units' => (int) (clone $windowQuery)->sum('reserved_units'),
            'consumed_units' => (int) (clone $windowQuery)->sum('consumed_units'),
            'exhausted_count' => (int) (clone $windowQuery)->whereRaw('(reserved_units + consumed_units) >= limit_units')->count(),
            'overcommitted_count' => (int) (clone $windowQuery)->whereRaw('(reserved_units + consumed_units) > limit_units')->count(),
        ];
        $capabilityStatuses = $this->groupedCounts(FulfilmentCapability::class, $workspaceId, 'status', self::CAPABILITY_STATES);
        $capabilityHealth = $this->groupedCounts(FulfilmentCapability::class, $workspaceId, 'health_state', self::CAPABILITY_HEALTH_STATES);

        return [
            'capabilities' => [
                'count' => $this->scopedCount(FulfilmentCapability::class, $workspaceId),
                'by_status' => $capabilityStatuses,
                'by_health_state' => $capabilityHealth,
                'unavailable_count' => (int) $this->scoped(FulfilmentCapability::class, $workspaceId)->where(function (Builder $query): void {
                    $query->where('status', '!=', 'active')->orWhere('health_state', '!=', 'healthy');
                })->count(),
            ],
            'capacity_windows' => $windows,
            'requests' => [
                'count' => $this->scopedCount(FulfilmentRequest::class, $workspaceId),
                'by_state' => $requests,
                'backlog_count' => (clone $backlogQuery)->count(),
                'oldest_backlog_age_seconds' => $this->oldestAgeFromQuery($backlogQuery, 'created_at', $now),
                'overdue_count' => (clone $overdueQuery)->count(),
                'oldest_overdue_age_seconds' => $this->oldestAgeFromQuery($overdueQuery, 'due_at', $now),
            ],
            'backlog_count' => (clone $backlogQuery)->count(),
            'sla_breach_count' => (clone $overdueQuery)->count(),
            'capacity_exhaustion_count' => $windows['exhausted_count'],
        ];
    }

    /** @return array<string,mixed> */
    private function conversionMetrics(?int $workspaceId, CarbonImmutable $now): array
    {
        $intakeStates = array_map(static fn (ConversionIntakeState $state): string => $state->value, ConversionIntakeState::cases());
        $verificationStates = array_map(static fn (ConversionVerificationState $state): string => $state->value, ConversionVerificationState::cases());
        $eventStates = array_map(static fn (ConversionEventState $state): string => $state->value, ConversionEventState::cases());
        $matchResults = array_map(static fn (ConversionMatchResult $state): string => $state->value, ConversionMatchResult::cases());
        $reviewActions = array_map(static fn (ConversionReviewAction $action): string => $action->value, ConversionReviewAction::cases());
        $receiptErrors = $this->errorCounts(ConversionEventReceipt::class, $workspaceId, 'safe_rejection_code');
        $events = $this->groupedCounts(ConversionEvent::class, $workspaceId, 'state', $eventStates);
        $receipts = $this->groupedCounts(ConversionEventReceipt::class, $workspaceId, 'intake_state', $intakeStates);
        $matches = $this->groupedCounts(ConversionEventMatch::class, $workspaceId, 'result', $matchResults);
        $reviews = $this->groupedCounts(ConversionEventReviewEvent::class, $workspaceId, 'action', $reviewActions);
        $signatureFailures = $this->scoped(ConversionEventReceipt::class, $workspaceId)
            ->where(function (Builder $query): void {
                $query->whereIn('safe_rejection_code', self::RETENTION_SIGNATURE_CODES)
                    ->orWhere('verification_state', ConversionVerificationState::Failed->value);
            })
            ->count();
        $identityBacklog = $this->scoped(ConversionEventReceipt::class, $workspaceId)
            ->whereNotNull('identity_expires_at')
            ->where('identity_expires_at', '<=', $now)
            ->whereNull('identity_redacted_at');

        return [
            'receipts' => [
                'count' => $this->scopedCount(ConversionEventReceipt::class, $workspaceId),
                'by_intake_state' => $receipts,
                'by_verification_state' => $this->groupedCounts(ConversionEventReceipt::class, $workspaceId, 'verification_state', $verificationStates),
                'errors' => $receiptErrors,
            ],
            'events' => [
                'count' => $this->scopedCount(ConversionEvent::class, $workspaceId),
                'by_state' => $events,
            ],
            'matches' => [
                'count' => $this->scopedCount(ConversionEventMatch::class, $workspaceId),
                'by_result' => $matches,
            ],
            'reviews' => [
                'count' => $this->scopedCount(ConversionEventReviewEvent::class, $workspaceId),
                'by_action' => $reviews,
            ],
            'duplicate_count' => ($receipts[ConversionIntakeState::Duplicate->value] ?? 0) + ($events[ConversionEventState::Duplicate->value] ?? 0),
            'rejected_count' => ($receipts[ConversionIntakeState::Rejected->value] ?? 0) + ($events[ConversionEventState::Rejected->value] ?? 0) + ($matches[ConversionMatchResult::Rejected->value] ?? 0),
            'ambiguous_count' => ($events[ConversionEventState::Ambiguous->value] ?? 0) + ($matches[ConversionMatchResult::Ambiguous->value] ?? 0),
            'unmatched_count' => ($events[ConversionEventState::Unmatched->value] ?? 0) + ($matches[ConversionMatchResult::Unmatched->value] ?? 0),
            'corrected_count' => ($events[ConversionEventState::Corrected->value] ?? 0) + ($reviews[ConversionReviewAction::Correct->value] ?? 0),
            'reversed_count' => ($events[ConversionEventState::Reversed->value] ?? 0) + ($reviews[ConversionReviewAction::Reverse->value] ?? 0),
            'signature_failure_count' => $signatureFailures,
            'ambiguity_backlog_count' => ($events[ConversionEventState::Ambiguous->value] ?? 0),
            'expiring_identity_backlog_count' => (clone $identityBacklog)->count(),
            'oldest_expiring_identity_age_seconds' => $this->oldestAgeFromQuery($identityBacklog, 'identity_expires_at', $now),
            'connections' => [
                'by_status' => $this->groupedCounts(ConversionSourceConnection::class, $workspaceId, 'status', array_map(static fn (ConversionConnectionStatus $state): string => $state->value, ConversionConnectionStatus::cases())),
                'credentials_by_status' => $this->groupedCounts(ConversionSourceConnectionVersion::class, $workspaceId, 'status', array_map(static fn (ConversionCredentialStatus $state): string => $state->value, ConversionCredentialStatus::cases())),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @return array<string,mixed>
     */
    private function retentionMetrics(?int $workspaceId, CarbonImmutable $now, array $metrics): array
    {
        $sourceObservationBacklog = $this->scoped(SourceObservation::class, $workspaceId)
            ->whereNotNull('retain_until')
            ->where('retain_until', '<=', $now)
            ->whereNull('expired_at');
        $externalIdentityBacklog = $this->scoped(ExternalIdentity::class, $workspaceId)
            ->whereNotNull('retain_until')
            ->where('retain_until', '<=', $now)
            ->where('state', '!=', 'deleted');
        $snapshotBacklog = (int) data_get($metrics, 'snapshots.expiry_backlog_count', 0);
        $conversionBacklog = (int) data_get($metrics, 'conversions.expiring_identity_backlog_count', 0);
        $sourceBacklog = (clone $sourceObservationBacklog)->count();
        $externalBacklog = (clone $externalIdentityBacklog)->count();

        return [
            'snapshot_expiry_backlog_count' => $snapshotBacklog,
            'source_observation_expiry_backlog_count' => $sourceBacklog,
            'external_identity_expiry_backlog_count' => $externalBacklog,
            'conversion_identity_expiry_backlog_count' => $conversionBacklog,
            'backlog_count' => $snapshotBacklog + $sourceBacklog + $externalBacklog + $conversionBacklog,
            'oldest_age_seconds' => $this->oldestAgeFromValues([
                $this->oldestAgeFromQuery($sourceObservationBacklog, 'retain_until', $now),
                $this->oldestAgeFromQuery($externalIdentityBacklog, 'retain_until', $now),
                data_get($metrics, 'snapshots.oldest_expiry_age_seconds'),
                data_get($metrics, 'conversions.oldest_expiring_identity_age_seconds'),
            ]),
        ];
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @return list<array{code:string,severity:string,value:int|float,threshold:int|float}>
     */
    private function alerts(array $metrics): array
    {
        $alerts = [];
        // The configured age threshold is already applied when selecting
        // stuck rows. Once one such row exists, the count itself should alert.
        $this->addAlert($alerts, 's2_research_stuck', 'critical', (int) data_get($metrics, 'research.stuck_count', 0), 1);
        $this->addAlert($alerts, 's2_provider_operation_stuck', 'critical', (int) data_get($metrics, 'provider_operations.stuck_count', 0), 1);
        $this->addAlert($alerts, 's2_held_budget_age', 'critical', (int) data_get($metrics, 'budget.oldest_hold_age_seconds', 0), $this->threshold('held_budget_age_seconds', 900));
        $this->addAlert($alerts, 's2_paid_result_loss', 'critical', max(
            (int) data_get($metrics, 'research.paid_result_loss.count', 0),
            (int) data_get($metrics, 'provider_operations.paid_result_loss_count', 0),
        ), $this->threshold('paid_result_loss_count', 1));
        $this->addAlert($alerts, 's2_output_invalid_rate', 'warning', (float) data_get($metrics, 'provider_operations.output_invalid_rate', 0.0), $this->configFloat('output_invalid_rate', 0.10));
        $manualReview = (int) data_get($metrics, 'provider_operations.ai.manual_review_count', 0)
            + (int) data_get($metrics, 'provider_operations.integrations.manual_review_count', 0)
            + (int) data_get($metrics, 'qualification.needs_review_count', 0)
            + (int) data_get($metrics, 'qualification.pending_review_count', 0);
        $this->addAlert($alerts, 's2_manual_review_backlog', 'warning', $manualReview, $this->threshold('manual_review_count', 1));
        $this->addAlert($alerts, 's2_cost_near_hard_maximum', 'warning', (int) data_get($metrics, 'budget.near_hard_maximum_count', 0), $this->threshold('cost_near_hard_maximum_count', 1));
        $this->addAlert($alerts, 's2_retention_backlog', 'critical', (int) data_get($metrics, 'retention.backlog_count', 0), $this->threshold('retention_backlog_count', 1));
        $this->addAlert($alerts, 's2_offer_dependency_failure', 'critical', (int) data_get($metrics, 'offers.active_dependency_failure_count', 0), $this->threshold('offer_dependency_failure_count', 1));
        $this->addAlert($alerts, 's2_fulfilment_sla_breach', 'critical', (int) data_get($metrics, 'fulfilment.sla_breach_count', 0), $this->threshold('fulfilment_sla_breach_count', 1));
        $this->addAlert($alerts, 's2_fulfilment_capacity_exhausted', 'critical', (int) data_get($metrics, 'fulfilment.capacity_exhaustion_count', 0), $this->threshold('capacity_exhaustion_count', 1));
        $this->addAlert($alerts, 's2_webhook_signature_failures', 'warning', (int) data_get($metrics, 'conversions.signature_failure_count', 0), $this->threshold('signature_failure_count', 1));
        $this->addAlert($alerts, 's2_conversion_match_ambiguity', 'critical', (int) data_get($metrics, 'conversions.ambiguity_backlog_count', 0), $this->threshold('conversion_ambiguity_count', 1));
        $this->addAlert($alerts, 's2_fence_rejections', 'warning', (int) data_get($metrics, 'research.fence_rejection_count', 0) + (int) data_get($metrics, 'provider_operations.fence_rejection_count', 0), $this->threshold('fence_rejection_count', 1));

        return $alerts;
    }

    /** @param list<array{code:string,severity:string,value:int|float,threshold:int|float}> $alerts */
    private function addAlert(array &$alerts, string $code, string $severity, int|float $value, int|float $threshold): void
    {
        if ($value < $threshold) {
            return;
        }
        $alerts[] = compact('code', 'severity', 'value', 'threshold');
    }

    /** @param list<array{code:string,severity:string,value:int|float,threshold:int|float}> $alerts */
    private function overallStatus(array $alerts): string
    {
        foreach ($alerts as $alert) {
            if ($alert['severity'] === 'critical') {
                return 'critical';
            }
        }

        return $alerts === [] ? 'ok' : 'warning';
    }

    /** @return array<string,mixed> */
    private function costEvidenceMetrics(?int $workspaceId): array
    {
        $sources = ['response', 'formula_estimate', 'provider_logs', 'generation_lookup', 'manual'];
        $counts = $this->groupedCounts(AiUsageLedger::class, $workspaceId, 'cost_evidence_source', $sources);

        return [
            'by_source' => $counts,
            'ledger_cost_micros' => (int) $this->scoped(AiUsageLedger::class, $workspaceId)->sum('cost_micros'),
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string,array{primary:string,fallback?:string|null}>  $dateSpecs
     * @return array<string,int|null>
     */
    private function stateAges(string $modelClass, ?int $workspaceId, string $stateColumn, array $dateSpecs, CarbonImmutable $now): array
    {
        $states = array_keys($dateSpecs);
        $ages = array_fill_keys([...$states, 'other'], null);
        if (! $this->hasTable($modelClass)) {
            return $ages;
        }
        foreach ($dateSpecs as $state => $spec) {
            $primary = $this->scoped($modelClass, $workspaceId)
                ->where($stateColumn, $state)
                ->whereNotNull($spec['primary'])
                ->min($spec['primary']);
            $fallback = null;
            if ($primary === null && isset($spec['fallback']) && $spec['fallback'] !== '') {
                $fallback = $this->scoped($modelClass, $workspaceId)
                    ->where($stateColumn, $state)
                    ->whereNotNull($spec['fallback'])
                    ->min($spec['fallback']);
            }
            $ages[$state] = $this->ageSeconds($primary ?? $fallback, $now);
        }

        return $ages;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<string>  $states
     * @param  array{primary:string,fallback?:string|null}  $dateSpec
     */
    private function staleByState(string $modelClass, ?int $workspaceId, string $stateColumn, array $states, array $dateSpec, CarbonImmutable $cutoff): int
    {
        if (! $this->hasTable($modelClass)) {
            return 0;
        }
        $total = 0;
        foreach ($states as $state) {
            $query = $this->scoped($modelClass, $workspaceId)
                ->where($stateColumn, $state)
                ->where($dateSpec['primary'], '<', $cutoff);
            if (isset($dateSpec['fallback']) && $dateSpec['fallback'] !== '') {
                $query = $this->scoped($modelClass, $workspaceId)
                    ->where($stateColumn, $state)
                    ->where(function (Builder $nested) use ($dateSpec, $cutoff): void {
                        $nested->where($dateSpec['primary'], '<', $cutoff)
                            ->orWhere(function (Builder $fallback) use ($dateSpec, $cutoff): void {
                                $fallback->whereNull($dateSpec['primary'])
                                    ->where($dateSpec['fallback'], '<', $cutoff);
                            });
                    });
            }
            $total += $query->count();
        }

        return $total;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<string>  $buckets
     * @return array<string,int>
     */
    private function groupedCounts(string $modelClass, ?int $workspaceId, string $column, array $buckets): array
    {
        $counts = array_fill_keys([...$buckets, 'other'], 0);
        if (! $this->hasTable($modelClass)) {
            return $counts;
        }
        $query = $this->scoped($modelClass, $workspaceId);
        foreach ($query->whereIn($column, $buckets)->select([$column])->selectRaw('COUNT(*) as aggregate')->groupBy($column)->get() as $row) {
            // Grouping columns are frequently enum-cast on the model. Metrics
            // labels must use their fixed persisted values, not enum objects.
            $key = (string) $row->getRawOriginal($column);
            if (array_key_exists($key, $counts) && $key !== 'other') {
                $counts[$key] = (int) $row->getAttribute('aggregate');
            }
        }
        $counts['other'] = (int) $this->scoped($modelClass, $workspaceId)->whereNotNull($column)->whereNotIn($column, $buckets)->count();

        return $counts;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<string,int>
     */
    private function errorCounts(string $modelClass, ?int $workspaceId, string $column): array
    {
        return $this->groupedCounts($modelClass, $workspaceId, $column, self::SAFE_ERROR_CODES);
    }

    /** @param class-string<Model> $modelClass */
    private function codeCount(string $modelClass, ?int $workspaceId, string $column, string $code): int
    {
        if (! $this->hasTable($modelClass)) {
            return 0;
        }

        return $this->scoped($modelClass, $workspaceId)->where($column, $code)->count();
    }

    /** @param class-string<Model> $modelClass */
    private function scopedCount(string $modelClass, ?int $workspaceId): int
    {
        return $this->hasTable($modelClass) ? $this->scoped($modelClass, $workspaceId)->count() : 0;
    }

    /** @param class-string<Model> $modelClass */
    private function fenceCount(string $modelClass, ?int $workspaceId, string $errorColumn): int
    {
        if (! $this->hasTable($modelClass)) {
            return 0;
        }

        return $this->scoped($modelClass, $workspaceId)
            ->where(function (Builder $query) use ($errorColumn): void {
                $query->where($errorColumn, 'fence_rejected')
                    ->orWhere($errorColumn, 'execution_fence_rejected');
            })
            ->count();
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{sample_count:int,avg_ms:int|null,max_ms:int|null}
     */
    private function latencyMetrics(string $modelClass, ?int $workspaceId, ?string $relation = null): array
    {
        if (! $this->hasTable($modelClass)) {
            return ['sample_count' => 0, 'avg_ms' => null, 'max_ms' => null];
        }
        $model = new $modelClass;
        $query = $model->newQuery();
        if ($workspaceId !== null) {
            if (Schema::hasColumn($model->getTable(), 'workspace_id')) {
                $query->where($model->getTable().'.workspace_id', $workspaceId);
            } elseif ($relation !== null) {
                $query->whereHas($relation, fn (Builder $operation): Builder => $operation->where('workspace_id', $workspaceId));
            }
        }
        $query->whereNotNull('latency_ms');
        $average = $query->avg('latency_ms');
        $maximum = (clone $query)->max('latency_ms');

        return [
            'sample_count' => $query->count(),
            'avg_ms' => $average === null ? null : (int) round((float) $average),
            'max_ms' => $maximum === null ? null : (int) $maximum,
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{item_count:int,row_count:int,truncated:bool}
     */
    private function boundedJsonArrayMetric(string $modelClass, ?int $workspaceId, string $column): array
    {
        if (! $this->hasTable($modelClass)) {
            return ['item_count' => 0, 'row_count' => 0, 'truncated' => false];
        }
        $query = $this->scoped($modelClass, $workspaceId);
        $total = (clone $query)->count();
        $rows = $query->select([$column])->orderBy('id')->limit($this->maxRows())->get();
        $items = 0;
        foreach ($rows as $row) {
            $value = $row->getAttribute($column);
            if (is_array($value)) {
                $items += count($value);
            }
        }

        return ['item_count' => $items, 'row_count' => $rows->count(), 'truncated' => $total > $rows->count()];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return Builder<Model>
     */
    private function scoped(string $modelClass, ?int $workspaceId): Builder
    {
        $query = $modelClass::query();
        if ($workspaceId !== null) {
            $query->where($query->getModel()->getTable().'.workspace_id', $workspaceId);
        }

        return $query;
    }

    /** @param class-string<Model> $modelClass */
    private function hasTable(string $modelClass): bool
    {
        $model = new $modelClass;
        $table = $model->getTable();
        if (! array_key_exists($table, $this->tableCache)) {
            $this->tableCache[$table] = Schema::hasTable($table);
        }

        return $this->tableCache[$table];
    }

    /** @param array<string,int|null> $ages */
    private function oldestAge(array $ages): ?int
    {
        $values = array_values(array_filter($ages, static fn (mixed $age): bool => is_int($age)));

        return $values === [] ? null : max($values);
    }

    /** @param list<int|null> $values */
    private function oldestAgeFromValues(array $values): ?int
    {
        $values = array_values(array_filter($values, static fn (mixed $value): bool => is_int($value)));

        return $values === [] ? null : max($values);
    }

    /** @param list<mixed> $values */
    private function oldestTimestamp(array $values, CarbonImmutable $now): ?int
    {
        $valid = array_values(array_filter($values, static fn (mixed $value): bool => $value !== null));
        if ($valid === []) {
            return null;
        }

        $oldest = null;
        foreach ($valid as $value) {
            $age = $this->ageSeconds($value, $now);
            if ($age !== null && ($oldest === null || $age > $oldest)) {
                $oldest = $age;
            }
        }

        return $oldest;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function oldestAgeFromQuery(Builder $query, string $column, CarbonImmutable $now): ?int
    {
        return $this->ageSeconds($query->whereNotNull($column)->min($column), $now);
    }

    private function ageSeconds(mixed $value, CarbonImmutable $now): ?int
    {
        if ($value === null) {
            return null;
        }
        try {
            $date = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value);

            return max(0, (int) $date->diffInSeconds($now));
        } catch (\Throwable) {
            return null;
        }
    }

    private function maxRows(): int
    {
        return max(1, min(10_000, (int) config('intelligence.operations.max_rows', 5000)));
    }

    private function threshold(string $key, int $fallback): int
    {
        $value = config('intelligence.operations.alerts.'.$key, $fallback);

        return is_numeric($value) ? max(0, (int) $value) : $fallback;
    }

    private function configFloat(string $key, float $fallback): float
    {
        $value = config('intelligence.operations.'.($key === 'cost_near_hard_maximum_ratio' ? $key : 'alerts.'.$key), $fallback);

        return is_numeric($value) ? max(0.0, (float) $value) : $fallback;
    }
}
