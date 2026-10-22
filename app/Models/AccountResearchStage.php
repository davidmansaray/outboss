<?php

namespace App\Models;

use App\Domain\Intelligence\Enums\AccountResearchStageKey;
use App\Domain\Intelligence\Enums\AccountResearchStageState;
use Database\Factories\AccountResearchStageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id', 'workspace_id', 'research_run_id', 'stage_order', 'stage_key', 'state', 'entry_predicate_snapshot',
    'missing_facts_snapshot', 'expected_cost_micros', 'maximum_cost_micros', 'implementation_version',
    'checkpoint_reference', 'checkpoint_hash', 'lease_owner', 'fencing_token', 'lease_expires_at',
    'safe_error_code', 'started_at', 'succeeded_at', 'failed_at',
])]
class AccountResearchStage extends Model
{
    /** @use HasFactory<AccountResearchStageFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'stage_key' => AccountResearchStageKey::class,
            'state' => AccountResearchStageState::class,
            'entry_predicate_snapshot' => 'array',
            'missing_facts_snapshot' => 'array',
            'checkpoint_reference' => 'array',
            'stage_order' => 'integer',
            'expected_cost_micros' => 'integer',
            'maximum_cost_micros' => 'integer',
            'implementation_version' => 'integer',
            'fencing_token' => 'integer',
            'lease_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AccountResearchRun, $this> */
    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(AccountResearchRun::class, 'research_run_id');
    }

    /** @return HasMany<AccountResearchAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(AccountResearchAttempt::class, 'stage_id');
    }
}
