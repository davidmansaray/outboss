<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * @property int $id
 * @property string $name
 * @property string $token
 * @property list<string>|null $abilities
 * @property string|null $display_prefix
 * @property int|null $workspace_id
 * @property string|null $device_session_id
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'display_prefix',
        'workspace_id',
        'device_session_id',
    ];

    /** @return BelongsTo<DeviceSession, $this> */
    public function deviceSession(): BelongsTo
    {
        return $this->belongsTo(DeviceSession::class);
    }

    /** Device-session tokens are minted by the iOS sign-in flow, not created as API keys. */
    public function isDeviceToken(): bool
    {
        return $this->device_session_id !== null;
    }

    public static function displayPrefixFrom(string $plainTextToken): string
    {
        $secret = str_contains($plainTextToken, '|')
            ? substr($plainTextToken, (int) strpos($plainTextToken, '|') + 1)
            : $plainTextToken;

        return substr($secret, 0, 8).'…';
    }
}
