<?php

namespace App\Models;

use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Enums\ProspectSourceStatus;
use Database\Factories\ProspectSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The aggregate root for a durable prospect origin.
 *
 * Configuration and policy live in immutable versions; this row only stores
 * the current pointers and mutable lifecycle metadata.
 *
 * @property ProspectSourceKind $kind
 * @property ProspectSourceStatus $status
 * @property ProspectSourceVersion|null $currentVersion
 */
#[Fillable([
    'id',
    'workspace_id',
    'current_version_id',
    'last_run_id',
    'status',
    'kind',
    'system_key',
    'display_name',
    'name_normalized',
    'active_name_normalized',
    'owner_user_id',
    'lock_version',
    'deletion_requested_at',
    'deletion_approved_version_id',
    'deletion_approved_policy_hash',
    'deletion_approved_behavior',
    'deletion_delete_canonical',
])]
class ProspectSource extends Model
{
    /** @use HasFactory<ProspectSourceFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'status' => ProspectSourceStatus::class,
            'kind' => ProspectSourceKind::class,
            'lock_version' => 'integer',
            'deletion_requested_at' => 'datetime',
            'deletion_delete_canonical' => 'boolean',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsTo<ProspectSourceVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ProspectSourceVersion::class, 'current_version_id');
    }

    /** @return BelongsTo<SourceRun, $this> */
    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(SourceRun::class, 'last_run_id');
    }

    /** @return HasMany<ProspectSourceVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ProspectSourceVersion::class);
    }

    /** @return HasMany<SourceRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(SourceRun::class);
    }

    /** @return HasMany<SourceObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(SourceObservation::class);
    }

    /** @return HasMany<AccountSourceMembership, $this> */
    public function accountMemberships(): HasMany
    {
        return $this->hasMany(AccountSourceMembership::class);
    }

    /** @return HasMany<PersonSourceMembership, $this> */
    public function personMemberships(): HasMany
    {
        return $this->hasMany(PersonSourceMembership::class);
    }
}
