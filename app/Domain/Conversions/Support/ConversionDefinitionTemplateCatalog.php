<?php

namespace App\Domain\Conversions\Support;

/**
 * Versioned system suggestions. Copies are ordinary workspace records and are
 * never read from this catalogue at intake time.
 */
final class ConversionDefinitionTemplateCatalog
{
    public const VERSION = 1;

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return [
            'meeting_booked' => [
                'event_key' => 'meeting_booked',
                'display_name' => 'Meeting booked',
                'description' => 'A qualified business meeting was booked.',
                'subject_type' => 'Account',
                'allowed_sources' => ['manual', 'api', 'webhook', 'email_reply'],
                'property_schema' => self::emptySchema(),
                'allowed_identity_keys' => ['account_id', 'external_identity', 'domain'],
                'dedupe_strategy' => 'source_and_external_id',
                'dedupe_configuration' => [],
                'attribution_window' => ['days' => 30],
                'value_policy' => ['allowed' => false, 'currency' => null],
                'correction_policy' => ['allowed' => true],
                'reversal_policy' => ['allowed' => true],
                'retention_policy' => ['unmatched_identity_days' => 30],
                'export_policy' => ['allowed' => true],
            ],
            'trial_started' => [
                'event_key' => 'trial_started',
                'display_name' => 'Trial started',
                'description' => 'An Account started a trial.',
                'subject_type' => 'Account',
                'allowed_sources' => ['manual', 'api', 'webhook'],
                'property_schema' => self::emptySchema(),
                'allowed_identity_keys' => ['account_id', 'external_identity', 'domain'],
                'dedupe_strategy' => 'source_and_external_id',
                'dedupe_configuration' => [],
                'attribution_window' => ['days' => 30],
                'value_policy' => ['allowed' => false, 'currency' => null],
                'correction_policy' => ['allowed' => true],
                'reversal_policy' => ['allowed' => true],
                'retention_policy' => ['unmatched_identity_days' => 30],
                'export_policy' => ['allowed' => true],
            ],
            'asset_requested' => [
                'event_key' => 'asset_requested',
                'display_name' => 'Asset requested',
                'description' => 'An Account requested the Offer asset.',
                'subject_type' => 'Account',
                'allowed_sources' => ['manual', 'api', 'webhook'],
                'property_schema' => self::emptySchema(),
                'allowed_identity_keys' => ['account_id', 'external_identity', 'domain'],
                'dedupe_strategy' => 'source_and_external_id',
                'dedupe_configuration' => [],
                'attribution_window' => ['days' => 30],
                'value_policy' => ['allowed' => false, 'currency' => null],
                'correction_policy' => ['allowed' => true],
                'reversal_policy' => ['allowed' => true],
                'retention_policy' => ['unmatched_identity_days' => 30],
                'export_policy' => ['allowed' => true],
            ],
            'offer_accepted' => [
                'event_key' => 'offer_accepted',
                'display_name' => 'Offer accepted',
                'description' => 'An Account accepted the Offer.',
                'subject_type' => 'Account',
                'allowed_sources' => ['manual', 'api', 'webhook'],
                'property_schema' => self::emptySchema(),
                'allowed_identity_keys' => ['account_id', 'external_identity', 'domain'],
                'dedupe_strategy' => 'source_and_external_id',
                'dedupe_configuration' => [],
                'attribution_window' => ['days' => 30],
                'value_policy' => ['allowed' => false, 'currency' => null],
                'correction_policy' => ['allowed' => true],
                'reversal_policy' => ['allowed' => true],
                'retention_policy' => ['unmatched_identity_days' => 30],
                'export_policy' => ['allowed' => true],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function emptySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'additionalProperties' => false,
        ];
    }
}
