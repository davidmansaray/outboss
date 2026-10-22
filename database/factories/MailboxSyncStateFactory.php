<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Enums\SyncStatus;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailboxSyncState>
 */
class MailboxSyncStateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mailbox_id' => Mailbox::factory(),
            'scope' => SyncScope::Inbox,
            'status' => SyncStatus::Idle,
        ];
    }
}
