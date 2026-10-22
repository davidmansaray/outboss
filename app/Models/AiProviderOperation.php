<?php

namespace App\Models;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $workspace_id
 * @property int $budget_period_id
 * @property string $budget_run_id
 * @property int $credential_id
 * @property int $credential_version_id
 * @property AiProviderName $provider
 * @property AiTask $task
 * @property AiOperationStatus $status
 * @property string $request_fingerprint
 * @property int $reserved_cost_micros
 * @property int $reserved_native_units
 * @property string $operation
 * @property string $model_or_endpoint
 * @property int $policy_version
 * @property string|null $provider_request_id
 * @property string|null $provider_generation_id
 * @property string|null $safe_request_id
 * @property string|null $provider_response_id
 * @property string|null $budget_envelope_id
 * @property array<string, mixed>|null $provider_metadata
 * @property int $fencing_token
 * @property CarbonImmutable|null $reconcile_after
 * @property CarbonImmutable|null $finalize_after
 * @property CarbonImmutable|null $lease_expires_at
 */
#[Fillable(['id', 'workspace_id', 'budget_period_id', 'budget_run_id', 'budget_envelope_id', 'credential_id', 'credential_version_id', 'provider', 'capability', 'task', 'operation', 'initiated_by_user_id', 'idempotency_key', 'request_fingerprint', 'model_or_endpoint', 'policy_version', 'pricing_version', 'status', 'reserved_native_units', 'reserved_cost_micros', 'actual_native_units', 'actual_cost_micros', 'cost_basis', 'provider_request_id', 'provider_generation_id', 'safe_request_id', 'provider_response_id', 'provider_metadata', 'fencing_token', 'lease_owner', 'lease_expires_at', 'retry_after_at', 'safe_error_code', 'safe_error_message', 'reserved_at', 'started_at', 'finished_at', 'reconcile_after', 'finalize_after'])]
class AiProviderOperation extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'provider' => AiProviderName::class,
            'task' => AiTask::class,
            'status' => AiOperationStatus::class,
            'budget_envelope_id' => 'string',
            'reserved_native_units' => 'integer',
            'reserved_cost_micros' => 'integer',
            'actual_native_units' => 'integer',
            'actual_cost_micros' => 'integer',
            'policy_version' => 'integer',
            'fencing_token' => 'integer',
            'provider_metadata' => 'array',
            'lease_expires_at' => 'datetime',
            'retry_after_at' => 'datetime',
            'reserved_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'reconcile_after' => 'datetime',
            'finalize_after' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiBudgetPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AiBudgetPeriod::class, 'budget_period_id');
    }

    /** @return BelongsTo<AiBudgetRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiBudgetRun::class, 'budget_run_id');
    }

    /** @return BelongsTo<AiBudgetEnvelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(AiBudgetEnvelope::class, 'budget_envelope_id');
    }

    /** @return BelongsTo<AiProviderCredential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(AiProviderCredential::class, 'credential_id');
    }
}
