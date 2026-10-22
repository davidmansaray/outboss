<?php

namespace App\Models;

use App\Domain\Integrations\Enums\ExternalUsageEntryType;
use Carbon\CarbonImmutable;
use Database\Factories\ExternalUsageEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $operation_id
 * @property ExternalUsageEntryType $entry_type
 * @property int|null $native_units
 * @property int $cost_micros
 * @property CarbonImmutable $occurred_at
 */
#[Fillable([
    'workspace_id',
    'operation_id',
    'budget_period_id',
    'registration_id',
    'provider',
    'capability',
    'operation',
    'endpoint',
    'entry_type',
    'native_unit',
    'native_units',
    'raw_provider_cost',
    'cost_micros',
    'cost_basis',
    'provider_request_id',
    'provider_snapshot_id',
    'occurred_at',
    'created_at',
])]
class ExternalUsageEntry extends Model
{
    /** @use HasFactory<ExternalUsageEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'entry_type' => ExternalUsageEntryType::class,
            'native_units' => 'integer',
            'cost_micros' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ExternalOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(ExternalOperation::class, 'operation_id');
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
}
