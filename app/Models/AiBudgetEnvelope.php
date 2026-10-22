<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product-workflow budget spanning more than one AI task run.
 *
 * The monthly period remains the outer admission boundary. This row is the
 * workflow-level hard maximum and is deliberately independent from provider
 * credentials or model policy so it can survive rotation and retries.
 */
#[Fillable(['id', 'workspace_id', 'kind', 'owner_key', 'hard_limit_micros', 'currency', 'pricing_version', 'catalogue_version', 'held_micros', 'committed_micros', 'state', 'opened_at', 'closed_at'])]
class AiBudgetEnvelope extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'hard_limit_micros' => 'integer',
            'pricing_version' => 'integer',
            'held_micros' => 'integer',
            'committed_micros' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<AiBudgetRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AiBudgetRun::class, 'budget_envelope_id');
    }

    /** @return HasMany<AiProviderOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(AiProviderOperation::class, 'budget_envelope_id');
    }
}
