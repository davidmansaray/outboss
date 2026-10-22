<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\Contracts\MailTransport;
use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\Contracts\OAuthMailboxConnector;
use App\Domain\Mail\Enums\MailProviderName;

/** The capabilities exposed by one registered mail provider. */
final readonly class ProviderDefinition
{
    public function __construct(
        public MailProviderName $name,
        public MailTransport $transport,
        public ?OAuthMailboxConnector $oauth = null,
        public ?ManagedMailboxProvisioner $provisioner = null,
    ) {}

    public function supportsOAuth(): bool
    {
        return $this->oauth !== null;
    }

    public function supportsProvisioning(): bool
    {
        return $this->provisioner !== null;
    }
}
