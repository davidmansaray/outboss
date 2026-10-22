<?php

namespace App\Domain\Devices\Support;

use App\Domain\Api\TokenScope;

/**
 * The fixed scopes every iOS access token carries. The app can read and reply
 * to the inbox; it cannot launch campaigns or change mailboxes and people.
 */
final class DeviceScopes
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            TokenScope::InboxRead->value,
            TokenScope::InboxSend->value,
            TokenScope::MailboxesRead->value,
            TokenScope::CampaignsRead->value,
            TokenScope::PeopleRead->value,
        ];
    }
}
