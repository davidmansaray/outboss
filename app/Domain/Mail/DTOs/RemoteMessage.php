<?php

namespace App\Domain\Mail\DTOs;

use App\Domain\Mail\Enums\MessageDirection;

readonly class RemoteMessage
{
    /**
     * @param  list<array{email: string, name?: string|null}>  $to
     * @param  list<array{email: string, name?: string|null}>  $cc
     * @param  list<array{email: string, name?: string|null}>  $bcc
     * @param  list<string>  $references
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $remoteMessageId,
        public ?string $remoteThreadId,
        public MessageDirection $direction,
        public string $subject,
        public string $bodyText,
        public ?string $bodyHtml,
        public ?string $fromEmail,
        public ?string $fromName,
        public array $to = [],
        public array $cc = [],
        public array $bcc = [],
        public ?string $internetMessageId = null,
        public ?string $inReplyTo = null,
        public array $references = [],
        public ?\DateTimeInterface $occurredAt = null,
        public bool $hasAttachments = false,
        public array $headers = [],
        public array $metadata = [],
        public bool $deleted = false,
    ) {}
}
