<?php

namespace App\Domain\Notifications;

final readonly class PushMessage
{
    /**
     * @param  array<string, mixed>  $data  Custom keys delivered beside "aps".
     */
    public function __construct(
        public string $title,
        public ?string $subtitle,
        public string $body,
        public int $badge,
        public string $threadGroup,
        public ?string $collapseId,
        public array $data = [],
    ) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $alert = array_filter([
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'body' => $this->body,
        ], fn (?string $value): bool => $value !== null && $value !== '');

        return [
            'aps' => [
                'alert' => $alert,
                'badge' => $this->badge,
                'sound' => 'default',
                'thread-id' => $this->threadGroup,
                'mutable-content' => 1,
            ],
            'outboss' => $this->data,
        ];
    }
}
