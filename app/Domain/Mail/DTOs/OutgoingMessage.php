<?php

namespace App\Domain\Mail\DTOs;

readonly class OutgoingMessage
{
    /**
     * @param  list<array{email: string, name?: string|null}>  $to
     * @param  list<string>  $references
     */
    public function __construct(
        public string $subject,
        public string $bodyText,
        public string $bodyHtml,
        public array $to,
        public string $fromEmail,
        public ?string $fromName = null,
        public ?string $clientMessageMarker = null,
        public ?string $inReplyTo = null,
        public array $references = [],
        public ?string $trackingToken = null,
        public ?string $listUnsubscribeUrl = null,
        public bool $oneClickPost = false,
    ) {}
}
