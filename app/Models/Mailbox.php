<?php

namespace App\Models;

use App\Domain\Mail\Enums\ConnectionStatus;
use Database\Factories\MailboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'workspace_id',
    'provider_connection_id',
    'provider_mailbox_id',
    'email_address',
    'display_name',
    'status',
    'provider_metadata',
    'sender_identities',
    'selected_sender_identity_key',
    'last_synced_at',
    'last_sync_error',
    'sync_requested_at',
    'daily_send_cap',
    'next_send_slot_at',
    'sending_paused_at',
    'sending_paused_reason',
])]
class Mailbox extends Model
{
    /** @use HasFactory<MailboxFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ConnectionStatus::class,
            'provider_metadata' => 'array',
            'sender_identities' => 'array',
            'last_synced_at' => 'datetime',
            'sync_requested_at' => 'datetime',
            'daily_send_cap' => 'integer',
            'next_send_slot_at' => 'datetime',
            'sending_paused_at' => 'datetime',
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
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'provider_connection_id');
    }

    /** @return HasMany<MailboxSyncState, $this> */
    public function syncStates(): HasMany
    {
        return $this->hasMany(MailboxSyncState::class);
    }

    /** @return HasOne<ManagedMailboxAccount, $this> */
    public function managedMailboxAccount(): HasOne
    {
        return $this->hasOne(ManagedMailboxAccount::class);
    }

    /** @return HasMany<Thread, $this> */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    /** @return BelongsToMany<Campaign, $this> */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class)->withPivot('position');
    }

    public function isAvailable(): bool
    {
        return $this->status === ConnectionStatus::Connected;
    }
}
