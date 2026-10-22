<?php

namespace App\Domain\Campaigns\DTOs;

final readonly class EnrollmentSkip
{
    public function __construct(
        public ?int $personId,
        public string $name,
        public string $reason,
    ) {}

    /**
     * @return array{person_id: int|null, name: string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'person_id' => $this->personId,
            'name' => $this->name,
            'reason' => $this->reason,
        ];
    }
}
