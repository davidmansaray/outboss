<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'workspace_id', 'audience_snapshot_id', 'audience_snapshot_member_id', 'prospect_source_id', 'prospect_source_version_id', 'source_observation_id', 'account_source_membership_id', 'person_source_membership_id', 'policy_hash', 'rights_result', 'observed_at', 'expires_at', 'created_at'])]
class AudienceSnapshotMemberSource extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['observed_at' => 'datetime', 'expires_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<AudienceSnapshotMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(AudienceSnapshotMember::class, 'audience_snapshot_member_id');
    }
}
