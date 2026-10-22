<?php

namespace App\Domain\Mail\Enums;

enum SendAttemptStatus: string
{
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case LeaseAcquired = 'lease_acquired';
    case Sending = 'sending';
    case ProviderAccepted = 'provider_accepted';
    case Sent = 'sent';
    case Deferred = 'deferred';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case ManualReview = 'manual_review';

    public function isPreProvider(): bool
    {
        return in_array($this, [
            self::Scheduled,
            self::Queued,
            self::Deferred,
            self::Failed,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Sent,
            self::Cancelled,
            self::ProviderAccepted,
        ], true);
    }

    /**
     * These states can still submit work or leave an uncertain provider
     * outcome. Deferred and pre-provider failed attempts are explicitly
     * excluded from completion: they have not been submitted and remain
     * auditable retry/dead-letter evidence.
     */
    public function blocksExperimentCompletion(): bool
    {
        return in_array($this, [
            self::Scheduled,
            self::Queued,
            self::LeaseAcquired,
            self::Sending,
            self::Unknown,
            self::ManualReview,
        ], true);
    }
}
