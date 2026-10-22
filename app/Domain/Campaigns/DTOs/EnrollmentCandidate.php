<?php

namespace App\Domain\Campaigns\DTOs;

final readonly class EnrollmentCandidate
{
    public function __construct(
        public ?int $personId,
        public ?int $accountId,
        public string $emailAddress,
        public string $emailNormalized,
        public ?string $fullName,
        public ?string $firstName,
        public ?string $company,
    ) {}
}
