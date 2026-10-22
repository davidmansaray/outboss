<?php

namespace App\Domain\Campaigns\DTOs;

final readonly class GovernedCampaignAction
{
    public function __construct(
        public string $actionId,
        public int $personId,
        public int $accountId,
        public int $mailboxId,
        public string $emailAddress,
        public string $emailNormalized,
        public ?string $fullName,
        public string $firstName,
        public string $company,
        public string $subjectTemplate,
        public string $bodyTemplate,
        public string $subject,
        public string $bodyText,
        public string $actionFingerprint,
        public string $contentHash,
    ) {}
}
