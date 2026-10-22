<?php

namespace App\Models;

use App\Domain\Intelligence\Enums\AccountResearchAttemptState;
use Database\Factories\AccountResearchAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id', 'workspace_id', 'research_run_id', 'stage_id', 'attempt_number', 'deterministic_idempotency_key',
    'provider_operation_id', 'input_hash', 'state', 'lease_owner', 'fencing_token', 'lease_expires_at',
    'outcome', 'safe_error_code', 'started_at', 'finished_at',
])]
class AccountResearchAttempt extends Model
{
    /** @use HasFactory<AccountResearchAttemptFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'state' => AccountResearchAttemptState::class,
            'attempt_number' => 'integer',
            'fencing_token' => 'integer',
            'outcome' => 'array',
            'lease_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AccountResearchRun, $this> */
    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(AccountResearchRun::class, 'research_run_id');
    }

    /** @return BelongsTo<AccountResearchStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(AccountResearchStage::class, 'stage_id');
    }

    /** @return BelongsTo<AiProviderOperation, $this> */
    public function providerOperation(): BelongsTo
    {
        return $this->belongsTo(AiProviderOperation::class, 'provider_operation_id');
    }
}
