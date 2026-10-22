<?php

namespace App\Models;

use App\Domain\Prospecting\Enums\SourceVersionOrigin;
use Database\Factories\ProspectSourceVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** @property array<string, mixed> $configuration */
/** @property array<string, mixed> $policy_snapshot */
#[Fillable([
    'id',
    'prospect_source_id',
    'workspace_id',
    'version',
    'schema_version',
    'configuration',
    'configuration_hash',
    'policy_snapshot',
    'policy_hash',
    'capability',
    'discovery_motion',
    'source_family',
    'provider_registration_id',
    'provider_registration_version',
    'origin',
    'actor_type',
    'actor_user_id',
    'actor_display_name',
    'created_at',
])]
class ProspectSourceVersion extends Model
{
    public const SCHEMA_VERSION = 1;

    /** @use HasFactory<ProspectSourceVersionFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Prospect source versions are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Prospect source versions are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'origin' => SourceVersionOrigin::class,
            'configuration' => 'array',
            'policy_snapshot' => 'array',
            'version' => 'integer',
            'schema_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProspectSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ProspectSource::class, 'prospect_source_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return HasMany<SourceRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(SourceRun::class, 'prospect_source_version_id');
    }

    /** @return HasMany<SourceObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(SourceObservation::class, 'prospect_source_version_id');
    }
}
