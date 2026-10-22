<?php

namespace App\Domain\Integrations\Providers\BrightData;

use App\Domain\Integrations\Contracts\CapabilityRequest;
use App\Domain\Integrations\Contracts\CapabilityResult;
use App\Domain\Integrations\DTOs\ExternalCostEstimate;
use App\Domain\Integrations\DTOs\SafeExternalError;
use App\Domain\Integrations\Providers\FakeCapabilityProvider;
use App\Models\ExternalOperation;

/**
 * Deterministic Bright Data contract fixture. Modes model sync, HTTP 202,
 * async snapshot, partial response, and schema drift without touching a live
 * endpoint or persisting provider response bodies.
 */
class FakeProvider extends FakeCapabilityProvider
{
    public static string $mode = 'sync';

    public static function reset(): void
    {
        parent::reset();
        self::$mode = 'sync';
    }

    public function __construct()
    {
        parent::__construct('bright_data', Definition::CAPABILITIES);
    }

    public function execute(CapabilityRequest $request): CapabilityResult
    {
        self::$calls[] = $request;
        $cost = new ExternalCostEstimate(2, 'records', self::$costMicros, 'bright_data_fixture', true);
        $records = [[
            'provider_record_id' => 'bd-record-001',
            'linkedin_url' => 'https://www.linkedin.com/company/example',
            'name' => 'Example Company',
        ]];

        return match (self::$mode) {
            'accepted_202', 'async' => new CapabilityResult(
                state: 'awaiting',
                cost: $cost,
                providerRequestId: 'bd-request-001',
                providerSnapshotId: 'bd-snapshot-001',
            ),
            'partial' => new CapabilityResult(
                state: 'partial',
                data: ['records' => $records],
                cost: $cost,
                providerRequestId: 'bd-request-partial',
                providerSnapshotId: 'bd-snapshot-partial',
                metadata: ['returned' => 1, 'errors' => 1],
            ),
            'schema_drift' => new CapabilityResult(
                state: 'failed',
                cost: $cost,
                error: new SafeExternalError('schema_drift', 'The Bright Data fixture returned an unsupported schema.'),
            ),
            'ambiguous' => new CapabilityResult(
                state: 'ambiguous',
                cost: $cost,
                providerRequestId: null,
                error: new SafeExternalError('provider_timeout', 'The provider response could not be confirmed.', true),
            ),
            default => new CapabilityResult(
                state: 'complete',
                data: ['records' => $records],
                cost: new ExternalCostEstimate(1, 'records', self::$costMicros, 'bright_data_fixture_exact', false),
                providerRequestId: 'bd-request-sync',
                providerSnapshotId: 'bd-snapshot-sync',
            ),
        };
    }

    public function reconcile(CapabilityRequest $request, ExternalOperation $operation): ?CapabilityResult
    {
        return new CapabilityResult(
            state: 'complete',
            data: ['records' => [[
                'provider_record_id' => 'bd-record-001',
                'linkedin_url' => 'https://www.linkedin.com/company/example',
                'name' => 'Example Company',
            ]]],
            cost: new ExternalCostEstimate(1, 'records', 0, 'already_reserved', false),
            providerRequestId: $operation->provider_request_id ?? 'bd-request-reconciled',
            providerSnapshotId: $operation->provider_snapshot_id ?? 'bd-snapshot-001',
        );
    }
}
