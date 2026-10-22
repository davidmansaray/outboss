<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $workspace_id
 * @property string|null $current_version_id
 * @property string $status
 * @property int $lock_version
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'id', 'workspace_id', 'current_version_id', 'status', 'lock_version',
    'client_operation_id', 'operation_hash', 'created_by_user_id',
])]
class Asset extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['lock_version' => 'integer'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<AssetVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class, 'current_version_id');
    }

    /** @return HasMany<AssetVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AssetVersion::class);
    }

    /** @return HasMany<AssetClientOperation, $this> */
    public function clientOperations(): HasMany
    {
        return $this->hasMany(AssetClientOperation::class);
    }
}
