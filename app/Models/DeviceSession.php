<?php

namespace App\Models;

use App\Domain\Devices\Enums\PushPreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A signed-in phone. It belongs to the user; each access token it mints is
 * bound to the workspace recorded here at the time.
 *
 * @property string $id
 * @property int $user_id
 * @property int|null $workspace_id
 * @property string $install_id
 * @property string $name
 * @property string $platform
 * @property string|null $app_version
 * @property string|null $apns_token
 * @property string|null $apns_environment
 * @property PushPreference $notify
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
#[Fillable([
    'user_id',
    'workspace_id',
    'install_id',
    'name',
    'platform',
    'app_version',
    'apns_token',
    'apns_environment',
    'notify',
    'last_used_at',
    'revoked_at',
])]
class DeviceSession extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'notify' => PushPreference::class,
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<DeviceRefreshToken, $this> */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(DeviceRefreshToken::class);
    }

    /** @return HasMany<PersonalAccessToken, $this> */
    public function accessTokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    /**
     * @param  Builder<DeviceSession>  $query
     * @return Builder<DeviceSession>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
