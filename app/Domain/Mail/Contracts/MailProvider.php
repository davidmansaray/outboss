<?php

namespace App\Domain\Mail\Contracts;

/**
 * Backwards-compatible aggregate for existing OAuth providers.
 *
 * New providers should implement only the capability they expose.  In
 * particular, managed JMAP providers implement MailTransport but not this
 * aggregate.
 */
interface MailProvider extends MailTransport, OAuthMailboxConnector {}
