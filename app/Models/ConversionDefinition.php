<?php

namespace App\Models;

use App\Domain\Conversions\Enums\ConversionDefinitionStatus;
use Database\Factories\ConversionDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property ConversionDefinitionStatus $status */
#[Fillable([
    'id',
    'workspace_id',
    'event_key',
    'current_version_id',
    'status',
    'lock_version',
    'created_by_user_id',
    'updated_by_user_id',
])]
class ConversionDefinition extends Model
{
    /** @use HasFactory<ConversionDefinitionFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'status' => ConversionDefinitionStatus::class,
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace,$this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ConversionDefinitionVersion,$this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ConversionDefinitionVersion::class, 'current_version_id');
    }

    /** @return HasMany<ConversionDefinitionVersion,$this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ConversionDefinitionVersion::class);
    }

    /** @return HasMany<ConversionSourceConnectionVersion,$this> */
    public function sourceConnectionVersions(): HasMany
    {
        return $this->hasMany(ConversionSourceConnectionVersion::class);
    }

    /** @return BelongsTo<User,$this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
