<?php

namespace App\Models;

use App\Domain\Conversions\Enums\ConversionConnectionKind;
use App\Domain\Conversions\Enums\ConversionConnectionStatus;
use Database\Factories\ConversionSourceConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id',
    'workspace_id',
    'public_id',
    'kind',
    'display_name',
    'status',
    'current_version_id',
    'last_used_at',
    'disabled_at',
    'created_by_user_id',
    'updated_by_user_id',
    'lock_version',
])]
class ConversionSourceConnection extends Model
{
    /** @use HasFactory<ConversionSourceConnectionFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'kind' => ConversionConnectionKind::class,
            'status' => ConversionConnectionStatus::class,
            'last_used_at' => 'datetime',
            'disabled_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace,$this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ConversionSourceConnectionVersion,$this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ConversionSourceConnectionVersion::class, 'current_version_id');
    }

    /** @return HasMany<ConversionSourceConnectionVersion,$this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ConversionSourceConnectionVersion::class, 'connection_id');
    }

    /** @return HasMany<ConversionEventReceipt,$this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(ConversionEventReceipt::class, 'connection_id');
    }

    /** @return BelongsTo<User,$this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
