<?php

namespace App\Domain\Mail\DTOs;

readonly class RemoteMessagePage
{
    /**
     * @param  list<RemoteMessage>  $messages
     * @param  array<string, mixed>  $nextState
     */
    public function __construct(
        public array $messages,
        public ?string $cursor = null,
        public ?int $highWaterTimestamp = null,
        public array $nextState = [],
        public bool $hasMore = false,
    ) {}
}
