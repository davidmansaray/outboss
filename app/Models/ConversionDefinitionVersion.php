<?php

namespace App\Models;

use App\Domain\Gtm\Support\ImmutableVersion;
use Database\Factories\ConversionDefinitionVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed> $property_schema
 * @property array{days?: int} $attribution_window
 */
#[Fillable([
    'id',
    'workspace_id',
    'conversion_definition_id',
    'version',
    'schema_version',
    'display_name',
    'description',
    'subject_type',
    'allowed_sources',
    'property_schema',
    'allowed_identity_keys',
    'dedupe_strategy',
    'dedupe_configuration',
    'attribution_window',
    'value_policy',
    'correction_policy',
    'reversal_policy',
    'retention_policy',
    'export_policy',
    'content_hash',
    'origin',
    'actor_type',
    'actor_user_id',
    'actor_display_name',
    'created_at',
])]
class ConversionDefinitionVersion extends Model
{
    public const SCHEMA_VERSION = 1;

    /** @use HasFactory<ConversionDefinitionVersionFactory> */
    use HasFactory;

    use HasUuids;
    use ImmutableVersion;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'schema_version' => 'integer',
            'allowed_sources' => 'array',
            'property_schema' => 'array',
            'allowed_identity_keys' => 'array',
            'dedupe_configuration' => 'array',
            'attribution_window' => 'array',
            'value_policy' => 'array',
            'correction_policy' => 'array',
            'reversal_policy' => 'array',
            'retention_policy' => 'array',
            'export_policy' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace,$this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ConversionDefinition,$this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ConversionDefinition::class, 'conversion_definition_id');
    }

    /** @return BelongsTo<User,$this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return HasMany<ConversionEvent,$this> */
    public function events(): HasMany
    {
        return $this->hasMany(ConversionEvent::class);
    }
}
