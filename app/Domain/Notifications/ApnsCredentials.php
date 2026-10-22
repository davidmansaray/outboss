<?php

namespace App\Domain\Notifications;

final readonly class ApnsCredentials
{
    public function __construct(
        public string $keyId,
        public string $teamId,
        public string $bundleId,
        public string $privateKey,
        public int $timeoutSeconds,
    ) {}
}
