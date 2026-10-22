<?php

namespace App\Models;

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiProviderName;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $capability
 * @property AiProviderName $provider
 * @property AiCredentialStatus $status
 * @property int|null $active_version_id
 * @property CarbonImmutable|null $last_tested_at
 * @property CarbonImmutable|null $last_healthy_at
 * @property CarbonImmutable|null $retry_after_at
 * @property string|null $provider_balance_summary
 * @property CarbonImmutable|null $balance_checked_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 */
#[Fillable(['workspace_id', 'capability', 'provider', 'status', 'active_version_id', 'last_tested_at', 'last_healthy_at', 'retry_after_at', 'provider_balance_summary', 'balance_checked_at', 'last_error_code', 'last_error_message', 'created_by_user_id', 'updated_by_user_id'])]
class AiProviderCredential extends Model
{
    protected function casts(): array
    {
        return [
            'provider' => AiProviderName::class,
            'status' => AiCredentialStatus::class,
            'last_tested_at' => 'datetime',
            'last_healthy_at' => 'datetime',
            'retry_after_at' => 'datetime',
            'balance_checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<AiProviderCredentialVersion, $this> */
    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(AiProviderCredentialVersion::class, 'active_version_id');
    }

    /** @return HasMany<AiProviderCredentialVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AiProviderCredentialVersion::class, 'credential_id');
    }

    /** @return HasMany<AiProviderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AiProviderEvent::class, 'credential_id');
    }
}
