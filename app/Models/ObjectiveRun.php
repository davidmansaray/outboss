<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\HasExecutionOrigin;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseName;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $workspace_id
 * @property string $objective_id
 * @property string $objective_version_id
 * @property string $audience_snapshot_id
 * @property string|null $gtm_experiment_id
 * @property int $run_number
 * @property ObjectiveRunState $state
 * @property ObjectiveRunPhaseName $current_phase
 * @property string $input_hash
 * @property string $idempotency_key_hash
 * @property string $schema_version
 * @property string|null $message_validator_version
 * @property int $maximum_cost_micros
 * @property int $reserved_cost_micros
 * @property int $actual_cost_micros
 * @property string|null $ai_budget_envelope_id
 * @property string|null $lease_owner
 * @property Carbon|null $lease_expires_at
 * @property int $fencing_token
 * @property Carbon|null $heartbeat_at
 * @property string|null $cancellation_reason_code
 * @property string|null $cancellation_detail
 * @property string|null $superseded_by_run_id
 * @property string|null $safe_error_code
 * @property string|null $safe_error_detail
 * @property int $created_by_user_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $superseded_at
 * @property-read User|null $createdBy
 * @property-read AiBudgetEnvelope|null $budgetEnvelope
 * @property-read Collection<int, ObjectiveRunArmDraft> $armDrafts
 */
#[Fillable([
    'origin', 'campaign_composition_id',
    'id', 'workspace_id', 'objective_id', 'objective_version_id', 'audience_snapshot_id',
    'gtm_experiment_id', 'run_number', 'state', 'current_phase', 'input_hash',
    'idempotency_key_hash', 'schema_version', 'message_validator_version', 'maximum_cost_micros', 'reserved_cost_micros',
    'actual_cost_micros', 'ai_budget_envelope_id', 'lease_owner', 'lease_expires_at',
    'fencing_token', 'heartbeat_at', 'cancellation_reason_code', 'cancellation_detail',
    'superseded_by_run_id', 'safe_error_code', 'safe_error_detail', 'created_by_user_id',
    'started_at', 'finished_at', 'cancelled_at', 'superseded_at',
])]
class ObjectiveRun extends Model
{
    use HasExecutionOrigin;
    use HasUuids;

    protected $attributes = ['origin' => 'ai'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'origin' => ExecutionOrigin::class,
            'run_number' => 'integer',
            'state' => ObjectiveRunState::class,
            'current_phase' => ObjectiveRunPhaseName::class,
            'maximum_cost_micros' => 'integer',
            'reserved_cost_micros' => 'integer',
            'actual_cost_micros' => 'integer',
            'fencing_token' => 'integer',
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Objective, $this> */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    /** @return BelongsTo<ObjectiveVersion, $this> */
    public function objectiveVersion(): BelongsTo
    {
        return $this->belongsTo(ObjectiveVersion::class);
    }

    /** @return BelongsTo<AudienceSnapshot, $this> */
    public function audienceSnapshot(): BelongsTo
    {
        return $this->belongsTo(AudienceSnapshot::class);
    }

    /** @return BelongsTo<GtmExperiment, $this> */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(GtmExperiment::class, 'gtm_experiment_id');
    }

    /** @return BelongsTo<AiBudgetEnvelope, $this> */
    public function budgetEnvelope(): BelongsTo
    {
        return $this->belongsTo(AiBudgetEnvelope::class, 'ai_budget_envelope_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<ObjectiveRun, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_run_id');
    }

    /** @return HasMany<ObjectiveRun, $this> */
    public function predecessors(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by_run_id');
    }

    /** @return HasMany<ObjectiveRunPhase, $this> */
    public function phases(): HasMany
    {
        return $this->hasMany(ObjectiveRunPhase::class);
    }

    /** @return HasMany<ObjectiveRunDispatch, $this> */
    public function dispatches(): HasMany
    {
        return $this->hasMany(ObjectiveRunDispatch::class);
    }

    /** @return HasMany<ObjectiveRunArmDraft, $this> */
    public function armDrafts(): HasMany
    {
        return $this->hasMany(ObjectiveRunArmDraft::class);
    }

    /** @return HasMany<ExecutionPlan, $this> */
    public function executionPlans(): HasMany
    {
        return $this->hasMany(ExecutionPlan::class);
    }
}
