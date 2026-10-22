<?php

namespace App\Domain\Integrations\Providers;

use App\Domain\Integrations\Contracts\CapabilityProvider;
use App\Domain\Integrations\Contracts\CapabilityRequest;
use App\Domain\Integrations\Contracts\CapabilityResult;
use App\Domain\Integrations\DTOs\ExternalCostEstimate;
use App\Domain\Integrations\DTOs\SafeExternalError;
use App\Models\ExternalOperation;
use Illuminate\Support\Str;

class FakeCapabilityProvider implements CapabilityProvider
{
    /** @var list<CapabilityRequest> */
    public static array $calls = [];

    /** @var array<string, list<CapabilityResult>> */
    public static array $queuedResults = [];

    public static ?SafeExternalError $nextError = null;

    public static int $costMicros = 1_000;

    /** @param list<string> $supported */
    public function __construct(protected readonly string $key = 'fake', protected readonly array $supported = []) {}

    public static function reset(): void
    {
        self::$calls = [];
        self::$queuedResults = [];
        self::$nextError = null;
        self::$costMicros = 1_000;
    }

    public static function queue(string $capability, CapabilityResult $result): void
    {
        self::$queuedResults[$capability][] = $result;
    }

    public function providerKey(): string
    {
        return $this->key;
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        if ($this->supported !== []) {
            return $this->supported;
        }

        $configured = config("integrations.providers.{$this->key}.capabilities", []);

        return array_values(array_filter($configured, 'is_string'));
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function execute(CapabilityRequest $request): CapabilityResult
    {
        self::$calls[] = $request;
        if (self::$nextError !== null) {
            $error = self::$nextError;
            self::$nextError = null;

            return new CapabilityResult($error->ambiguous ? 'ambiguous' : 'failed', error: $error);
        }
        if (isset(self::$queuedResults[$request->capability]) && self::$queuedResults[$request->capability] !== []) {
            return array_shift(self::$queuedResults[$request->capability]);
        }

        return new CapabilityResult(
            state: 'complete',
            data: $this->defaultData($request),
            cost: new ExternalCostEstimate(
                nativeUnits: 1,
                nativeUnit: 'requests',
                costMicros: self::$costMicros,
                costBasis: 'deterministic_fake',
                upperBound: false,
            ),
            providerRequestId: 'fake-request-'.Str::lower(Str::random(12)),
        );
    }

    public function reconcile(CapabilityRequest $request, ExternalOperation $operation): ?CapabilityResult
    {
        return $this->execute($request);
    }

    /** @return array<string, mixed> */
    protected function defaultData(CapabilityRequest $request): array
    {
        $limit = (int) ($request->input['limit'] ?? 1);
        $limit = max(1, min($limit, 1000));
        if (in_array($request->capability, ['signal.ingest', 'conversion.ingest'], true)) {
            return ['accepted' => count($request->input['records'] ?? $request->input['events'] ?? [])];
        }
        if ($request->capability === 'email.verify') {
            return ['records' => array_map(
                static fn (string $email): array => ['email' => $email, 'status' => 'deliverable'],
                array_slice($request->input['emails'] ?? [], 0, $limit),
            )];
        }

        return ['records' => [[
            'id' => 'fake-record-'.Str::lower(Str::random(8)),
            'name' => (string) ($request->input['query'] ?? 'Fake record'),
        ]]];
    }
}
