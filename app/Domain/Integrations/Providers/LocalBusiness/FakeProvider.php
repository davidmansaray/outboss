<?php

namespace App\Domain\Integrations\Providers\LocalBusiness;

use App\Domain\Integrations\Contracts\CapabilityRequest;
use App\Domain\Integrations\Contracts\CapabilityResult;
use App\Domain\Integrations\DTOs\ExternalCostEstimate;
use App\Domain\Integrations\DTOs\SafeExternalError;
use App\Domain\Integrations\Providers\FakeCapabilityProvider;
use App\Models\ExternalOperation;

/**
 * Fixture-only adapter for the three local-business execution shapes. It is
 * not registered in production and does not perform network activity.
 */
class FakeProvider extends FakeCapabilityProvider
{
    public static string $scenario = 'places_pages';

    public static function reset(): void
    {
        parent::reset();
        self::$scenario = 'places_pages';
    }

    public function __construct(string $providerKey = 'google_places')
    {
        parent::__construct($providerKey, ['account.discover']);
    }

    public function execute(CapabilityRequest $request): CapabilityResult
    {
        self::$calls[] = $request;
        $cost = new ExternalCostEstimate(1, 'requests', self::$costMicros, 'deterministic_fake', false);
        $records = [
            [
                'provider_record_id' => 'place-001',
                'name' => 'Northstar Coffee',
                'website' => 'https://northstar.example',
                'place_id' => 'ChIJfake001',
                'maps_url' => 'https://maps.google.test/?cid=001',
            ],
            [
                'provider_record_id' => 'place-002',
                'name' => 'Harbour Studio',
                'website' => 'https://harbour.example',
                'place_id' => 'ChIJfake002',
                'maps_url' => 'https://maps.google.test/?cid=002',
            ],
        ];

        return match (self::$scenario) {
            'partial' => new CapabilityResult(
                state: 'partial',
                data: ['records' => [$records[0]]],
                cost: $cost,
                providerRequestId: 'maps-request-partial',
                metadata: ['stop_reason' => 'provider_page_limit'],
            ),
            'blocked' => new CapabilityResult(
                state: 'failed',
                cost: $cost,
                error: new SafeExternalError('provider_blocked', 'The candidate adapter stopped after a block response.'),
            ),
            'parser_drift' => new CapabilityResult(
                state: 'failed',
                cost: $cost,
                error: new SafeExternalError('schema_drift', 'The candidate adapter returned an unsupported schema.'),
            ),
            'hosted_async' => new CapabilityResult(
                state: 'awaiting',
                cost: $cost,
                providerRequestId: 'hosted-job-001',
                providerSnapshotId: 'hosted-snapshot-001',
            ),
            'self_hosted_cells' => new CapabilityResult(
                state: 'complete',
                data: ['records' => $records, 'next_cursor' => null],
                cost: $cost,
                providerRequestId: 'cell-job-001',
                metadata: ['cells' => ['cell-1', 'cell-2'], 'parser_version' => 'fixture-1'],
            ),
            default => new CapabilityResult(
                state: 'complete',
                data: ['records' => $records, 'next_cursor' => null],
                cost: $cost,
                providerRequestId: 'places-page-001',
                metadata: ['page_token' => 'fixture-next-page'],
            ),
        };
    }

    public function reconcile(CapabilityRequest $request, ExternalOperation $operation): ?CapabilityResult
    {
        if (self::$scenario === 'hosted_async') {
            return new CapabilityResult(
                state: 'complete',
                data: ['records' => [[
                    'provider_record_id' => 'place-001',
                    'name' => 'Northstar Coffee',
                    'place_id' => 'ChIJfake001',
                    'maps_url' => 'https://maps.google.test/?cid=001',
                ]]],
                cost: new ExternalCostEstimate(1, 'requests', 0, 'already_reserved', false),
                providerSnapshotId: $operation->provider_snapshot_id ?? 'hosted-snapshot-001',
            );
        }

        return $this->execute($request);
    }
}
