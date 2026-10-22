<?php

namespace App\Domain\Devices\Enums;

enum PushPreference: string
{
    /** Every new reply that lands in Needs reply. */
    case Needs = 'needs';

    /** Only replies from recipients marked interested. */
    case Leads = 'leads';

    /** No alerts; the badge still updates when the app refreshes. */
    case Off = 'off';
}
