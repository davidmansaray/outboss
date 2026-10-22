<?php

namespace App\Models;

use App\Domain\Intelligence\Enums\ResearchClaimStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property int $account_id
 * @property string|null $supersedes_claim_id
 * @property string $predicate
 * @property mixed $value_json
 * @property ResearchClaimStatus $status
 * @property string $content_hash
 * @property Carbon|null $fresh_until
 */
#[Fillable([
    'id', 'workspace_id', 'account_id', 'research_run_id', 'supersedes_claim_id', 'subject_type', 'predicate',
    'value_type', 'value_json', 'value_hash', 'status', 'confidence', 'calibration_band', 'observed_at',
    'fresh_until', 'expires_at', 'extractor_kind', 'extractor_version', 'prompt_version', 'model_version',
    'policy_version', 'catalogue_version', 'safe_for_message', 'content_hash',
])]
class ResearchClaim extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Research claims are immutable; append a successor claim.');
        });
        static::deleting(function (): never {
            throw new LogicException('Research claims are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ResearchClaimStatus::class,
            'value_json' => 'json',
            'confidence' => 'decimal:4',
            'safe_for_message' => 'boolean',
            'observed_at' => 'datetime',
            'fresh_until' => 'datetime',
            'expires_at' => 'datetime',
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

    /** @return BelongsTo<ResearchClaim, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_claim_id');
    }

    /** @return HasMany<ResearchClaimEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(ResearchClaimEvidence::class, 'claim_id');
    }

    /** @return HasMany<ResearchClaimEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ResearchClaimEvent::class, 'claim_id');
    }
}
