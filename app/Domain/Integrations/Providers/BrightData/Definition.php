<?php

namespace App\Domain\Integrations\Providers\BrightData;

/**
 * Bright Data is represented as a server-installed, disabled definition in
 * S1. No connection can be created until the S5.3 acceptance gate is passed.
 */
final readonly class Definition
{
    public const PROVIDER = 'bright_data';

    public const HOST = 'https://api.brightdata.com';

    public const DISABLED_REASON = 's5_3_acceptance_gate';

    /** @var list<string> */
    public const CAPABILITIES = [
        'account.discover',
        'person.discover',
        'account.enrich',
        'person.enrich',
    ];

    public function __construct(
        public string $providerKey = self::PROVIDER,
        public string $displayName = 'Bright Data (planned)',
        public bool $enabled = false,
        public string $availabilityReason = self::DISABLED_REASON,
    ) {}

    public static function instance(): self
    {
        return new self;
    }

    public function available(): bool
    {
        return $this->enabled;
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return self::CAPABILITIES;
    }

    /** @return array<string, mixed> */
    public function registrationMetadata(): array
    {
        return [
            'provider' => self::PROVIDER,
            'host' => self::HOST,
            'capabilities' => self::CAPABILITIES,
            'enabled' => $this->enabled,
            'availability_reason' => $this->availabilityReason,
            'server_installed_only' => true,
        ];
    }
}
