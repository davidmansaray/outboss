<?php

namespace App\Domain\Notifications;

/**
 * Bound when APNs is not configured, so the rest of the app never needs to
 * check whether push is available before notifying.
 */
class NullPushGateway implements PushGateway
{
    public function send(string $deviceToken, string $environment, PushMessage $message): PushResult
    {
        return PushResult::Unavailable;
    }
}
