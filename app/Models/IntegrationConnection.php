<?php

namespace App\Models;

use App\Domain\Integrations\Enums\IntegrationConnectionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\IntegrationConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $provider
 * @property IntegrationConnectionStatus $status
 * @property int|null $active_credential_version_id
 * @property CarbonImmutable|null $last_tested_at
 * @property CarbonImmutable|null $last_healthy_at
 * @property CarbonImmutable|null $retry_after_at
 */
#[Fillable([
    'workspace_id',
    'provider',
    'status',
    'active_credential_version_id',
    'last_tested_at',
    'last_healthy_at',
    'retry_after_at',
    'last_error_code',
    'last_error_message',
    'created_by_user_id',
    'updated_by_user_id',
])]
class IntegrationConnection extends Model
{
    /** @use HasFactory<IntegrationConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => IntegrationConnectionStatus::class,
            'active_credential_version_id' => 'integer',
            'last_tested_at' => 'datetime',
            'last_healthy_at' => 'datetime',
            'retry_after_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<IntegrationCredentialVersion, $this> */
    public function activeCredentialVersion(): BelongsTo
    {
        return $this->belongsTo(IntegrationCredentialVersion::class, 'active_credential_version_id');
    }

    /** @return HasMany<IntegrationCredentialVersion, $this> */
    public function credentialVersions(): HasMany
    {
        return $this->hasMany(IntegrationCredentialVersion::class, 'connection_id');
    }

    /** @return HasMany<IntegrationProviderRegistration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(IntegrationProviderRegistration::class, 'connection_id');
    }

    /** @return HasMany<ExternalOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(ExternalOperation::class, 'connection_id');
    }
}
