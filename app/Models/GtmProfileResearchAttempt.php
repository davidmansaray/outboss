<?php

namespace App\Models;

use Database\Factories\GtmProfileResearchAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'phase_id',
    'research_run_id',
    'attempt_number',
    'deterministic_idempotency_key',
    'provider_operation_id',
    'input_hash',
    'status',
    'lease_owner',
    'fencing_token',
    'lease_expires_at',
    'outcome',
    'safe_error_code',
    'started_at',
    'finished_at',
])]
class GtmProfileResearchAttempt extends Model
{
    /** @use HasFactory<GtmProfileResearchAttemptFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'fencing_token' => 'integer',
            'lease_expires_at' => 'datetime',
            'outcome' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GtmProfileResearchPhase, $this> */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(GtmProfileResearchPhase::class, 'phase_id');
    }

    /** @return BelongsTo<GtmProfileResearchRun, $this> */
    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(GtmProfileResearchRun::class, 'research_run_id');
    }

    /** @return BelongsTo<AiProviderOperation, $this> */
    public function providerOperation(): BelongsTo
    {
        return $this->belongsTo(AiProviderOperation::class, 'provider_operation_id');
    }
}
