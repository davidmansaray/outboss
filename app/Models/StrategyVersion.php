<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<string> $proof_claim_ids
 * @property array<string, mixed> $personalisation_contract
 * @property array<string, mixed> $message_generation_contract
 * @property array<string, mixed> $stop_conditions
 */
#[Fillable([
    'id', 'workspace_id', 'strategy_id', 'version', 'objective_version_id', 'audience_snapshot_id',
    'offer_version_id', 'name', 'positioning', 'problem_frame', 'proof_summary', 'cta_kind', 'cta_text',
    'proof_claim_ids', 'personalisation_contract', 'message_generation_contract', 'stop_conditions',
    'channel', 'sequence_contract', 'schema_version', 'content_hash', 'created_by_user_id', 'created_at',
])]
class StrategyVersion extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'proof_claim_ids' => 'array',
            'personalisation_contract' => 'array',
            'message_generation_contract' => 'array',
            'stop_conditions' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Strategy, $this> */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }
}
