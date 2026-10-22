<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only, safe audit history for managed mail operations. */
#[Fillable([
    'workspace_id',
    'managed_mail_domain_id',
    'managed_mailbox_account_id',
    'actor_user_id',
    'actor_type',
    'event',
    'from_state',
    'to_state',
    'safe_metadata',
    'created_at',
])]
class ManagedMailEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'safe_metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ManagedMailDomain, $this> */
    public function managedMailDomain(): BelongsTo
    {
        return $this->belongsTo(ManagedMailDomain::class);
    }

    /** @return BelongsTo<ManagedMailboxAccount, $this> */
    public function managedMailboxAccount(): BelongsTo
    {
        return $this->belongsTo(ManagedMailboxAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
