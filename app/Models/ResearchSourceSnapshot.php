<?php

namespace App\Models;

use App\Domain\Intelligence\Enums\ResearchSourceSnapshotState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'id', 'workspace_id', 'account_id', 'research_run_id', 'stage_id', 'source_type', 'canonical_public_url',
    'registrable_domain', 'retrieved_at', 'observed_at', 'content_hash', 'content', 'payload_reference',
    'mime_type', 'byte_count', 'language', 'retrieval_capability', 'retrieval_version', 'provider_operation_id',
    'provider_request_id', 'provider_item_rank', 'retrieval_policy_hash', 'display_classification',
    'llm_use_classification', 'export_classification', 'retention_classification', 'expires_at', 'redacted_at',
    'state', 'safe_failure_code', 'result_identity',
])]
class ResearchSourceSnapshot extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Research source snapshots are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Research source snapshots are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'state' => ResearchSourceSnapshotState::class,
            'retrieved_at' => 'datetime',
            'observed_at' => 'datetime',
            'expires_at' => 'datetime',
            'redacted_at' => 'datetime',
            'byte_count' => 'integer',
            'retrieval_version' => 'integer',
            'provider_item_rank' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
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

    /** @return HasMany<ResearchClaimEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(ResearchClaimEvidence::class, 'source_snapshot_id');
    }
}
