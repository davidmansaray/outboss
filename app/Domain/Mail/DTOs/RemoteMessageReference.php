<?php

namespace App\Domain\Mail\DTOs;

readonly class RemoteMessageReference
{
    public function __construct(
        public string $remoteMessageId,
        public ?string $remoteThreadId = null,
        public ?string $internetMessageId = null,
        public ?string $subject = null,
    ) {}
}
