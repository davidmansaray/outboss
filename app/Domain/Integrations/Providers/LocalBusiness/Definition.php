<?php

namespace App\Domain\Integrations\Providers\LocalBusiness;

/**
 * Candidate local-business adapters share one provider-neutral source motion.
 * They are deliberately disabled until the S5.1 selection gate is accepted.
 */
final readonly class Definition
{
    public const CAPABILITY = 'account.discover';

    public const MOTION = 'account.discover';

    public const SOURCE_FAMILY = 'local_business';

    public const DISABLED_REASON = 'not_enabled_selection_gate';

    public function __construct(
        public string $providerKey,
        public string $displayName,
        public string $executionShape,
        public bool $enabled = false,
        public string $availabilityReason = self::DISABLED_REASON,
    ) {}

    /** @return list<self> */
    public static function candidates(): array
    {
        return [
            new self('google_places', 'Google Places (candidate)', 'bounded_api_pages'),
            new self('hosted_maps_collector', 'Hosted Maps collector (candidate)', 'async_job_polling'),
            new self('google_maps_self_hosted', 'Self-hosted Maps collector (candidate)', 'geographic_cells'),
        ];
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::candidates();
    }

    public function available(): bool
    {
        return $this->enabled;
    }

    /** @return array<string, mixed> */
    public function registrationMetadata(): array
    {
        return [
            'provider' => $this->providerKey,
            'capability' => self::CAPABILITY,
            'motion' => self::MOTION,
            'source_family' => self::SOURCE_FAMILY,
            'execution_shape' => $this->executionShape,
            'enabled' => $this->enabled,
            'availability_reason' => $this->availabilityReason,
        ];
    }
}
