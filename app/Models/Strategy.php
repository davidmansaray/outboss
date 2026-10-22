<?php

namespace App\Models;

use App\Domain\Objectives\Enums\StrategyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property StrategyStatus $status */
#[Fillable(['id', 'workspace_id', 'current_version_id', 'status', 'lock_version', 'created_by_user_id', 'updated_by_user_id'])]
class Strategy extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['status' => StrategyStatus::class, 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<StrategyVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(StrategyVersion::class, 'current_version_id');
    }

    /** @return HasMany<StrategyVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(StrategyVersion::class);
    }
}
