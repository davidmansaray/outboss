<?php

namespace App\Models;

use App\Domain\Intelligence\Enums\ResearchClaimEvidenceEdgeType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'id', 'workspace_id', 'claim_id', 'source_snapshot_id', 'edge_type', 'locator', 'locator_hash', 'quote_hash',
    'evidence_weight', 'extraction_version',
])]
class ResearchClaimEvidence extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Research claim evidence is immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Research claim evidence is immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'edge_type' => ResearchClaimEvidenceEdgeType::class,
            'evidence_weight' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ResearchClaim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(ResearchClaim::class, 'claim_id');
    }

    /** @return BelongsTo<ResearchSourceSnapshot, $this> */
    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(ResearchSourceSnapshot::class, 'source_snapshot_id');
    }
}
