<?php

namespace App\Models;

use App\Domain\Intelligence\Enums\AccountResearchRunState;
use Database\Factories\AccountResearchRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id', 'workspace_id', 'account_id', 'gtm_profile_version_id', 'offer_version_id', 'parent_run_id',
    'account_snapshot', 'policy_snapshot', 'capability_snapshot', 'freshness_policy', 'maximum_cost_micros',
    'currency', 'input_hash', 'idempotency_key', 'orchestration_version', 'state', 'awaiting_reason',
    'safe_failure_code', 'current_stage', 'estimated_cost_micros', 'reserved_cost_micros', 'actual_cost_micros',
    'budget_envelope_id', 'research_budget_run_id', 'qualification_budget_run_id', 'initiated_by_user_id',
    'queued_at', 'started_at', 'finished_at', 'cancelled_at',
])]
class AccountResearchRun extends Model
{
    /** @use HasFactory<AccountResearchRunFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'state' => AccountResearchRunState::class,
            'account_snapshot' => 'array',
            'policy_snapshot' => 'array',
            'capability_snapshot' => 'array',
            'freshness_policy' => 'array',
            'maximum_cost_micros' => 'integer',
            'orchestration_version' => 'integer',
            'estimated_cost_micros' => 'integer',
            'reserved_cost_micros' => 'integer',
            'actual_cost_micros' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<GtmProfileVersion, $this> */
    public function gtmProfileVersion(): BelongsTo
    {
        return $this->belongsTo(GtmProfileVersion::class, 'gtm_profile_version_id');
    }

    /** @return BelongsTo<OfferVersion, $this> */
    public function offerVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class, 'offer_version_id');
    }

    /** @return BelongsTo<AccountResearchRun, $this> */
    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    /** @return HasMany<AccountResearchRun, $this> */
    public function childRuns(): HasMany
    {
        return $this->hasMany(self::class, 'parent_run_id');
    }

    /** @return BelongsTo<AiBudgetRun, $this> */
    public function researchBudgetRun(): BelongsTo
    {
        return $this->belongsTo(AiBudgetRun::class, 'research_budget_run_id');
    }

    /** @return BelongsTo<AiBudgetRun, $this> */
    public function qualificationBudgetRun(): BelongsTo
    {
        return $this->belongsTo(AiBudgetRun::class, 'qualification_budget_run_id');
    }

    /**
     * Reconstruct the run's provider cost projection from its immutable AI
     * operation rows. The denormalized run columns are initialized at start
     * and are not the accounting source of truth after a provider boundary.
     *
     * The workspace predicate is intentional defense in depth: budget-run
     * identifiers are scoped edges, but read models must never aggregate an
     * operation from another workspace even if a malformed row exists.
     *
     * @return array{reserved_cost_micros:int,actual_cost_micros:int}
     */
    public function costProjection(): array
    {
        $budgetRunIds = array_values(array_unique(array_filter([
            $this->research_budget_run_id,
            $this->qualification_budget_run_id,
        ], static fn (mixed $id): bool => is_string($id) && $id !== '')));

        if ($budgetRunIds === []) {
            return [
                'reserved_cost_micros' => (int) $this->reserved_cost_micros,
                'actual_cost_micros' => (int) $this->actual_cost_micros,
            ];
        }

        $operations = AiProviderOperation::query()
            ->where('workspace_id', $this->workspace_id)
            ->whereIn('budget_run_id', $budgetRunIds);

        return [
            // A provider operation keeps its original reservation as the
            // run-level estimate, including after settlement. This matches
            // the provider-operation and workspace export projections.
            'reserved_cost_micros' => (int) (clone $operations)->sum('reserved_cost_micros'),
            'actual_cost_micros' => (int) (clone $operations)->whereNotNull('actual_cost_micros')->sum('actual_cost_micros'),
        ];
    }

    /** @return BelongsTo<AiBudgetEnvelope, $this> */
    public function budgetEnvelope(): BelongsTo
    {
        return $this->belongsTo(AiBudgetEnvelope::class, 'budget_envelope_id');
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /** @return HasMany<AccountResearchRunInput, $this> */
    public function inputs(): HasMany
    {
        return $this->hasMany(AccountResearchRunInput::class, 'research_run_id');
    }

    /** @return HasMany<AccountResearchStage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(AccountResearchStage::class, 'research_run_id');
    }

    /** @return HasMany<AccountResearchAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(AccountResearchAttempt::class, 'research_run_id');
    }

    /** @return HasMany<AccountResearchDispatch, $this> */
    public function dispatches(): HasMany
    {
        return $this->hasMany(AccountResearchDispatch::class, 'research_run_id');
    }

    /** @return HasMany<ResearchSourceSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(ResearchSourceSnapshot::class, 'research_run_id');
    }

    /** @return HasMany<ResearchClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(ResearchClaim::class, 'research_run_id');
    }

    /** @return HasMany<QualificationDecision, $this> */
    public function qualificationDecisions(): HasMany
    {
        return $this->hasMany(QualificationDecision::class, 'research_run_id');
    }
}
