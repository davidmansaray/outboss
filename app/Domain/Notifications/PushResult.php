<?php

namespace App\Domain\Notifications;

enum PushResult: string
{
    case Delivered = 'delivered';

    /** Apple no longer recognises the device token; stop sending to it. */
    case Unregistered = 'unregistered';

    /** Throttled or a server error; worth retrying later. */
    case Retryable = 'retryable';

    /** Rejected for a reason a retry will not fix. */
    case Rejected = 'rejected';

    /** Push is not configured on this server. */
    case Unavailable = 'unavailable';
}
