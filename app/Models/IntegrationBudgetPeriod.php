<?php

namespace App\Models;

use Database\Factories\IntegrationBudgetPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $limit_snapshot_micros
 * @property int $held_micros
 * @property int $committed_micros
 * @property bool $over_limit
 * @property bool $budget_breach
 * @property int $settings_version
 */
#[Fillable([
    'workspace_id',
    'period_start_utc',
    'period_end_utc',
    'limit_snapshot_micros',
    'held_micros',
    'committed_micros',
    'over_limit',
    'budget_breach',
    'settings_version',
])]
class IntegrationBudgetPeriod extends Model
{
    /** @use HasFactory<IntegrationBudgetPeriodFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'period_start_utc' => 'date',
            'period_end_utc' => 'date',
            'limit_snapshot_micros' => 'integer',
            'held_micros' => 'integer',
            'committed_micros' => 'integer',
            'over_limit' => 'boolean',
            'budget_breach' => 'boolean',
            'settings_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<ExternalOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(ExternalOperation::class, 'budget_period_id');
    }
}
