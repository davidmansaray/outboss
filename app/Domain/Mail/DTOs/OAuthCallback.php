<?php

namespace App\Domain\Mail\DTOs;

readonly class OAuthCallback
{
    /**
     * @param  array<string, mixed>  $extras  Provider-specific callback query params
     *                                        (e.g. Zoho location / accounts-server).
     */
    public function __construct(
        public string $code,
        public string $state,
        public ?string $error = null,
        public array $extras = [],
    ) {}
}
