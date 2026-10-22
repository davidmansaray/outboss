<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One-time code handed to the iOS app after the person allows access in the
 * web sign-in sheet. Only the sha256 hash is stored, and it is bound to the
 * PKCE challenge and redirect URI the app started with.
 *
 * @property int $id
 * @property string $code_hash
 * @property int $user_id
 * @property int $workspace_id
 * @property string $code_challenge
 * @property string $redirect_uri
 * @property string|null $device_name
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
#[Fillable([
    'code_hash',
    'user_id',
    'workspace_id',
    'code_challenge',
    'redirect_uri',
    'device_name',
    'expires_at',
    'used_at',
])]
class MobileAuthorizationCode extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
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
}
