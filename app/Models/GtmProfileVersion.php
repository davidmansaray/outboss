<?php

namespace App\Models;

use App\Domain\Gtm\Enums\GtmProfileVersionActor;
use App\Domain\Gtm\Enums\GtmProfileVersionOrigin;
use App\Domain\Gtm\Support\ImmutableVersion;
use Database\Factories\GtmProfileVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed> $payload */
#[Fillable([
    'id',
    'workspace_id',
    'gtm_profile_id',
    'version',
    'schema_version',
    'payload',
    'content_hash',
    'actor_type',
    'actor_user_id',
    'actor_display_name',
    'origin',
    'created_at',
])]
class GtmProfileVersion extends Model
{
    /** Version of the canonical payload serializer implemented by this code. */
    public const SCHEMA_VERSION = 1;

    /** @use HasFactory<GtmProfileVersionFactory> */
    use HasFactory;

    use HasUuids;
    use ImmutableVersion;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            $attributes = $version->getAttributes();
            $workspaceId = $attributes['workspace_id'] ?? null;
            $profileId = $attributes['gtm_profile_id'] ?? null;

            if ($workspaceId === null && is_string($profileId) && $profileId !== '') {
                $version->setAttribute(
                    'workspace_id',
                    GtmProfile::query()->whereKey($profileId)->value('workspace_id'),
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'actor_type' => GtmProfileVersionActor::class,
            'origin' => GtmProfileVersionOrigin::class,
            'payload' => 'array',
            'version' => 'integer',
            'schema_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GtmProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(GtmProfile::class, 'gtm_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
