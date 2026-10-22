<?php

namespace App\Models;

use App\Domain\Integrations\Enums\IntegrationOperationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ExternalOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $workspace_id
 * @property int|null $budget_period_id
 * @property string|null $source_run_id
 * @property int $registration_id
 * @property int $connection_id
 * @property int $credential_version_id
 * @property string $provider
 * @property string $capability
 * @property string $operation
 * @property string $idempotency_key
 * @property string $request_fingerprint
 * @property IntegrationOperationStatus $status
 * @property int $reserved_native_units
 * @property int $reserved_cost_micros
 * @property int|null $actual_native_units
 * @property int|null $actual_cost_micros
 * @property string|null $native_unit
 * @property string|null $provider_request_id
 * @property string|null $provider_snapshot_id
 * @property CarbonImmutable|null $lease_expires_at
 * @property CarbonImmutable|null $reconcile_after
 * @property CarbonImmutable|null $finalize_after
 */
#[Fillable([
    'id',
    'workspace_id',
    'budget_period_id',
    'source_run_id',
    'registration_id',
    'connection_id',
    'credential_version_id',
    'provider',
    'capability',
    'operation',
    'idempotency_key',
    'request_fingerprint',
    'capability_schema_version',
    'pricing_version',
    'policy_version',
    'endpoint',
    'status',
    'native_unit',
    'reserved_native_units',
    'reserved_cost_micros',
    'actual_native_units',
    'actual_cost_micros',
    'cost_basis',
    'provider_request_id',
    'provider_snapshot_id',
    'safe_request_id',
    'safe_metadata',
    'result_reference',
    'initiated_by_user_id',
    'lease_owner',
    'lease_expires_at',
    'retry_after_at',
    'safe_error_code',
    'safe_error_message',
    'reserved_at',
    'started_at',
    'finished_at',
    'reconcile_after',
    'finalize_after',
])]
class ExternalOperation extends Model
{
    /** @use HasFactory<ExternalOperationFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'status' => IntegrationOperationStatus::class,
            'capability_schema_version' => 'integer',
            'pricing_version' => 'integer',
            'policy_version' => 'integer',
            'reserved_native_units' => 'integer',
            'reserved_cost_micros' => 'integer',
            'actual_native_units' => 'integer',
            'actual_cost_micros' => 'integer',
            'safe_metadata' => 'array',
            'result_reference' => 'array',
            'lease_expires_at' => 'datetime',
            'retry_after_at' => 'datetime',
            'reserved_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'reconcile_after' => 'datetime',
            'finalize_after' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<IntegrationBudgetPeriod, $this> */
    public function budgetPeriod(): BelongsTo
    {
        return $this->belongsTo(IntegrationBudgetPeriod::class, 'budget_period_id');
    }

    /** @return BelongsTo<IntegrationProviderRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(IntegrationProviderRegistration::class, 'registration_id');
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }

    /** @return BelongsTo<IntegrationCredentialVersion, $this> */
    public function credentialVersion(): BelongsTo
    {
        return $this->belongsTo(IntegrationCredentialVersion::class, 'credential_version_id');
    }

    /** @return HasMany<ExternalOperationAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(ExternalOperationAttempt::class, 'operation_id');
    }

    /** @return HasMany<ExternalUsageEntry, $this> */
    public function usageEntries(): HasMany
    {
        return $this->hasMany(ExternalUsageEntry::class, 'operation_id');
    }
}
