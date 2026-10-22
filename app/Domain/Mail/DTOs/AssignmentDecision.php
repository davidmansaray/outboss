<?php

namespace App\Domain\Mail\DTOs;

use App\Domain\Mail\Enums\RecipientMailboxProvider;

final readonly class AssignmentDecision
{
    public function __construct(
        public string $emailNormalized,
        public string $recipientDomain,
        public RecipientMailboxProvider $recipientProvider,
        public string $stratumKey,
        public string $assignmentKey,
        public ?string $cohortKey,
        public ?string $exclusionReason = null,
        public ?int $personId = null,
        public ?int $accountId = null,
        public ?string $emailAddress = null,
        public ?string $fullName = null,
        public ?string $firstName = null,
        public ?string $company = null,
        /** @var list<string> */
        public array $providerMxSnapshot = [],
    ) {}

    public function isExcluded(): bool
    {
        return $this->exclusionReason !== null;
    }

    /** @return array<string, int|string|null|list<string>> */
    public function toArray(): array
    {
        return [
            'email_normalized' => $this->emailNormalized,
            'recipient_domain' => $this->recipientDomain,
            'recipient_provider' => $this->recipientProvider->value,
            'stratum_key' => $this->stratumKey,
            'assignment_key' => $this->assignmentKey,
            'cohort' => $this->cohortKey,
            'exclusion_reason' => $this->exclusionReason,
            'person_id' => $this->personId,
            'account_id' => $this->accountId,
            'email_address' => $this->emailAddress,
            'full_name' => $this->fullName,
            'first_name' => $this->firstName,
            'company' => $this->company,
            'provider_mx_snapshot' => $this->providerMxSnapshot,
        ];
    }
}
