<?php

namespace App\Models;

use App\Domain\Integrations\DTOs\ProviderRegistrationReference;
use Database\Factories\IntegrationProviderRegistrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $connection_id
 * @property string $provider
 * @property string $capability
 * @property int $capability_schema_version
 * @property int $pricing_version
 * @property int $policy_version
 * @property string $code_definition_hash
 * @property array<string, mixed>|null $coverage
 * @property array<string, mixed>|null $limits
 * @property bool $enabled
 */
#[Fillable([
    'workspace_id',
    'connection_id',
    'provider',
    'adapter_version',
    'capability',
    'capability_schema_version',
    'pricing_version',
    'policy_version',
    'code_definition_hash',
    'coverage',
    'limits',
    'enabled',
    'enabled_at',
    'created_by_user_id',
    'updated_by_user_id',
])]
class IntegrationProviderRegistration extends Model
{
    /** @use HasFactory<IntegrationProviderRegistrationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'capability_schema_version' => 'integer',
            'pricing_version' => 'integer',
            'policy_version' => 'integer',
            'coverage' => 'array',
            'limits' => 'array',
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }

    /** @return HasMany<IntegrationCapabilityRoute, $this> */
    public function routes(): HasMany
    {
        return $this->hasMany(IntegrationCapabilityRoute::class, 'active_registration_id');
    }

    /** @return HasMany<ExternalOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(ExternalOperation::class, 'registration_id');
    }

    public function reference(): ProviderRegistrationReference
    {
        return new ProviderRegistrationReference(
            id: (int) $this->id,
            workspaceId: (int) $this->workspace_id,
            provider: (string) $this->provider,
            capability: (string) $this->capability,
            adapterVersion: (string) $this->adapter_version,
            capabilitySchemaVersion: (int) $this->capability_schema_version,
            pricingVersion: (int) $this->pricing_version,
            policyVersion: (int) $this->policy_version,
            codeDefinitionHash: (string) $this->code_definition_hash,
            enabled: (bool) $this->enabled,
        );
    }
}
