<?php

namespace App\Domain\Mail\Enums;

/**
 * Describes how a provider connection is authenticated.
 *
 * This is deliberately separate from the provider name. A managed Stalwart
 * connection still uses the normal ProviderConnection and Mailbox records,
 * but it has no OAuth redirect or refresh lifecycle.
 */
enum ConnectionKind: string
{
    case OAuth = 'oauth';
    case Managed = 'managed';
}
