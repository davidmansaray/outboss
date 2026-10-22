<?php

namespace App\Models;

use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Enums\SyncStatus;
use Database\Factories\MailboxSyncStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'mailbox_id',
    'scope',
    'remote_folder_id',
    'cursor',
    'high_water_timestamp',
    'high_water_remote_ids',
    'scan_state',
    'status',
    'last_started_at',
    'last_completed_at',
    'last_error',
])]
class MailboxSyncState extends Model
{
    /** @use HasFactory<MailboxSyncStateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'scope' => SyncScope::class,
            'status' => SyncStatus::class,
            'high_water_remote_ids' => 'array',
            'scan_state' => 'array',
            'last_started_at' => 'datetime',
            'last_completed_at' => 'datetime',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
