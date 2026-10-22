<?php

namespace App\Models;

use App\Domain\Objectives\Enums\OutreachPolicyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'workspace_id', 'current_version_id', 'status', 'execution_paused_at', 'execution_paused_reason', 'execution_paused_by_user_id', 'lock_version', 'created_by_user_id'])]
class OutreachPolicy extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['status' => OutreachPolicyStatus::class, 'execution_paused_at' => 'datetime', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<OutreachPolicyVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(OutreachPolicyVersion::class, 'current_version_id');
    }

    /** @return HasMany<OutreachPolicyVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(OutreachPolicyVersion::class);
    }
}
