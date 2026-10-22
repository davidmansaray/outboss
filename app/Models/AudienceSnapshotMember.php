<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use App\Domain\Objectives\Enums\AudienceMemberResult;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property AudienceMemberResult $result
 * @property int|null $account_id
 * @property int|null $person_id
 * @property string|null $qualification_decision_id
 * @property string|null $reason_to_contact_version_id
 * @property list<array{claim_id?: string, content_hash?: string, predicate?: string, fresh_until?: string|null}> $evidence_manifest
 * @property Carbon|null $evidence_fresh_until
 */
#[Fillable(['id', 'workspace_id', 'audience_snapshot_id', 'account_id', 'person_id', 'result', 'qualification_decision_id', 'reason_to_contact_version_id', 'criterion_results', 'inclusion_reasons', 'exclusion_reasons', 'missing_fields', 'suppression_snapshot', 'evidence_manifest', 'qualification_content_hash', 'reason_content_hash', 'source_lineage_hash', 'member_hash', 'evidence_fresh_until', 'evaluated_at', 'created_at'])]
class AudienceSnapshotMember extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'result' => AudienceMemberResult::class,
            'criterion_results' => 'array',
            'inclusion_reasons' => 'array',
            'exclusion_reasons' => 'array',
            'missing_fields' => 'array',
            'suppression_snapshot' => 'array',
            'evidence_manifest' => 'array',
            'evidence_fresh_until' => 'datetime',
            'evaluated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AudienceSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(AudienceSnapshot::class, 'audience_snapshot_id');
    }

    /** @return HasMany<AudienceSnapshotMemberSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(AudienceSnapshotMemberSource::class);
    }
}
