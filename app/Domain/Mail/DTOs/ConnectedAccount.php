<?php

namespace App\Domain\Mail\DTOs;

readonly class ConnectedAccount
{
    /**
     * @param  list<RemoteMailbox>  $mailboxes
     * @param  list<string>  $grantedScopes
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $providerUserId,
        public string $accessToken,
        public ?string $refreshToken,
        public ?\DateTimeInterface $tokenExpiresAt,
        public array $grantedScopes,
        public array $mailboxes,
        public array $metadata = [],
    ) {}
}
