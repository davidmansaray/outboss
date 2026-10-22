<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id', 'workspace_id', 'research_run_id', 'input_type', 'source_observation_id', 'prior_claim_id',
    'source_policy_hash', 'llm_use_classification', 'display_classification', 'export_classification',
    'observed_at', 'expires_at', 'content_hash',
])]
class AccountResearchRunInput extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['observed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<AccountResearchRun, $this> */
    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(AccountResearchRun::class, 'research_run_id');
    }

    /** @return BelongsTo<SourceObservation, $this> */
    public function sourceObservation(): BelongsTo
    {
        return $this->belongsTo(SourceObservation::class, 'source_observation_id');
    }

    /** @return BelongsTo<ResearchClaim, $this> */
    public function priorClaim(): BelongsTo
    {
        return $this->belongsTo(ResearchClaim::class, 'prior_claim_id');
    }
}
