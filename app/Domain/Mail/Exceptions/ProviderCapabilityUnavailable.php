<?php

namespace App\Domain\Mail\Exceptions;

use RuntimeException;

/** Safe, provider-neutral error for requesting an unsupported capability. */
class ProviderCapabilityUnavailable extends RuntimeException
{
    public function __construct(string $provider, string $capability)
    {
        parent::__construct('provider_capability_unavailable:'.$provider.':'.$capability);
    }
}
