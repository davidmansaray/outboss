<?php

namespace App\Domain\Campaigns\DTOs;

final readonly class EnrollmentResult
{
    /**
     * @param  list<EnrollmentSkip>  $skipped
     */
    public function __construct(
        public int $enrolled,
        public array $skipped,
    ) {}

    /**
     * @return array{enrolled: int, skipped: list<array{person_id: int|null, name: string, reason: string}>}
     */
    public function toArray(): array
    {
        return [
            'enrolled' => $this->enrolled,
            'skipped' => array_map(
                fn (EnrollmentSkip $skip): array => $skip->toArray(),
                $this->skipped,
            ),
        ];
    }
}
