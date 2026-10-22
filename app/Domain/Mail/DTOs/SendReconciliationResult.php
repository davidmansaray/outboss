<?php

namespace App\Domain\Mail\DTOs;

/**
 * Provider-neutral outcome of searching for an ambiguously submitted send.
 *
 * The status is intentionally a string: manual_review is a provider outcome
 * and is not the same thing as the operator's later SendResolution enum.
 */
readonly class SendReconciliationResult
{
    public string $resolution;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $status,
        public ?SendResult $sendResult = null,
        public ?RemoteMessage $message = null,
        public ?string $safeErrorCode = null,
        public ?string $safeErrorMessage = null,
        public array $metadata = [],
    ) {
        $this->resolution = $status;
    }

    /** @param array<string, mixed> $metadata */
    public static function confirmedSent(?SendResult $result = null, ?RemoteMessage $message = null, array $metadata = []): self
    {
        return new self('confirmed_sent', $result, $message, metadata: $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public static function confirmedNotSent(array $metadata = []): self
    {
        return new self('confirmed_not_sent', metadata: $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public static function pending(
        string $code = 'provider_reconciliation_pending',
        ?string $message = null,
        array $metadata = [],
    ): self {
        return new self(
            'pending',
            safeErrorCode: $code,
            safeErrorMessage: $message ?? 'The provider result is not visible yet; retry reconciliation before deciding how to proceed.',
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $metadata */
    public static function manualReview(string $code = 'provider_reconciliation_ambiguous', ?string $message = null, array $metadata = []): self
    {
        return new self('manual_review', safeErrorCode: $code, safeErrorMessage: $message, metadata: $metadata);
    }

    public function isConfirmedSent(): bool
    {
        return $this->status === 'confirmed_sent';
    }

    public function isConfirmedNotSent(): bool
    {
        return $this->status === 'confirmed_not_sent';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function requiresManualReview(): bool
    {
        return $this->status === 'manual_review';
    }
}
