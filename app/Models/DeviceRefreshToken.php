<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Single-use refresh token. Only the sha256 hash is stored.
 *
 * @property int $id
 * @property string $device_session_id
 * @property string $token_hash
 * @property int|null $replaced_by_id
 * @property Carbon|null $used_at
 * @property Carbon $expires_at
 */
#[Fillable(['device_session_id', 'token_hash', 'replaced_by_id', 'used_at', 'expires_at'])]
class DeviceRefreshToken extends Model
{
    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DeviceSession, $this> */
    public function deviceSession(): BelongsTo
    {
        return $this->belongsTo(DeviceSession::class);
    }
}
