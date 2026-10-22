<?php

namespace App\Domain\Mail\DTOs;

readonly class RefreshedTokens
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?\DateTimeInterface $tokenExpiresAt,
    ) {}
}
