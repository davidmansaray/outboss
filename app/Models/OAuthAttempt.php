<?php

namespace App\Models;

use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\OAuthIntent;
use Database\Factories\OAuthAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'workspace_id',
    'provider',
    'state_hash',
    'pkce_verifier',
    'reconnect_connection_id',
    'reconnect_mailbox_id',
    'intent',
    'expires_at',
    'consumed_at',
])]
#[Hidden(['pkce_verifier'])]
class OAuthAttempt extends Model
{
    /** @use HasFactory<OAuthAttemptFactory> */
    use HasFactory;

    protected $table = 'oauth_attempts';

    protected function casts(): array
    {
        return [
            'provider' => MailProviderName::class,
            'intent' => OAuthIntent::class,
            'pkce_verifier' => 'encrypted',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
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

    /** @return BelongsTo<ProviderConnection, $this> */
    public function reconnectConnection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'reconnect_connection_id');
    }

    /** @return BelongsTo<Mailbox, $this> */
    public function reconnectMailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'reconnect_mailbox_id');
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
