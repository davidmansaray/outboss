<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Conversions\Services\CreateConversionSourceConnection;
use App\Domain\Conversions\Services\DisableConversionSourceConnection;
use App\Domain\Conversions\Services\RotateConversionSourceConnection;
use App\Http\Controllers\Api\V1\Concerns\HandlesS2Transport;
use App\Http\Requests\DisableConversionConnectionRequest;
use App\Http\Requests\RotateConversionConnectionRequest;
use App\Http\Requests\StoreConversionConnectionRequest;
use App\Http\Resources\Api\V1\ConversionSourceConnectionResource;
use App\Models\ConversionSourceConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceClientOperation;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ConversionConnectionController
{
    use HandlesS2Transport;

    public function store(
        StoreConversionConnectionRequest $request,
        CreateConversionSourceConnection $create,
    ): JsonResponse|ConversionSourceConnectionResource {
        $data = $request->validated();
        $secretProvided = array_key_exists('secret', $data);
        $providedSecret = $secretProvided ? (string) $data['secret'] : null;
        unset($data['secret'], $data['idempotency_key']);

        return $this->executeOperation(
            $request,
            'store',
            null,
            $data,
            $secretProvided,
            $providedSecret,
            201,
            function () use ($request, $create, $data, $providedSecret): array {
                $secret = $providedSecret ?? bin2hex(random_bytes(32));
                $connection = $create->handle(
                    $request->user()->currentWorkspace(),
                    $request->user(),
                    (string) $data['kind'],
                    $secret,
                    $this->options($data),
                );

                return [$connection, $secret];
            },
        );
    }

    public function rotate(
        RotateConversionConnectionRequest $request,
        string $connection,
        RotateConversionSourceConnection $rotate,
    ): JsonResponse|ConversionSourceConnectionResource {
        $model = $this->connection($request, $connection);
        $data = $request->validated();
        $secretProvided = array_key_exists('secret', $data);
        $providedSecret = $secretProvided ? (string) $data['secret'] : null;
        unset($data['secret'], $data['expected_lock_version'], $data['idempotency_key']);

        return $this->executeOperation(
            $request,
            'rotate',
            (string) $model->id,
            [...$data, 'expected_lock_version' => $request->validated('expected_lock_version')],
            $secretProvided,
            $providedSecret,
            200,
            function () use ($request, $connection, $rotate, $data, $providedSecret): array {
                $current = $this->connection($request, $connection);
                $this->assertExpectedLockVersion($current, $request->validated());
                $secret = $providedSecret ?? bin2hex(random_bytes(32));
                $updated = $rotate->handle(
                    $current,
                    $request->user(),
                    $secret,
                    $this->options($data, $current),
                );

                return [$updated, $secret];
            },
        );
    }

    public function disable(
        DisableConversionConnectionRequest $request,
        string $connection,
        DisableConversionSourceConnection $disable,
    ): JsonResponse|ConversionSourceConnectionResource {
        $model = $this->connection($request, $connection);
        $data = $request->validated();
        unset($data['idempotency_key']);

        return $this->executeOperation(
            $request,
            'disable',
            (string) $model->id,
            [...$data],
            false,
            null,
            200,
            function () use ($request, $connection, $disable): array {
                $current = $this->connection($request, $connection);
                $this->assertExpectedLockVersion($current, $request->validated());
                $updated = $disable->handle($current, $request->user());

                return [$updated, null];
            },
        );
    }

    /**
     * Persist the client operation beside the connection mutation. The
     * generic workspace operation ledger is append-only and therefore also
     * covers session-authenticated requests, which do not pass through the
     * token idempotency replay middleware.
     *
     * @param  array<string,mixed>  $input
     * @param  Closure(): array{0: ConversionSourceConnection, 1: ?string}  $mutate
     */
    private function executeOperation(
        Request $request,
        string $action,
        ?string $connectionId,
        array $input,
        bool $secretProvided,
        ?string $providedSecret,
        int $status,
        Closure $mutate,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $workspace = $actor->currentWorkspace();
        $key = $this->connectionOperationKey($request);
        $fingerprint = $this->operationFingerprint(
            $action,
            (int) $workspace->id,
            $connectionId,
            $input,
            $secretProvided,
            $providedSecret,
        );

        try {
            return DB::transaction(function () use (
                $actor,
                $workspace,
                $key,
                $fingerprint,
                $action,
                $status,
                $mutate,
            ): JsonResponse {
                $operation = $this->findOperation($actor, $key);
                if ($operation !== null) {
                    return $this->replayOperation($operation, $workspace, $fingerprint);
                }

                [$connection, $secret] = $mutate();
                WorkspaceClientOperation::query()->create([
                    'initiating_user_id' => $actor->id,
                    'client_operation_id' => $key,
                    'server_fingerprint' => $fingerprint,
                    'resulting_workspace_id' => $workspace->id,
                    'redirect_route' => 'conversion-connections.'.$action,
                    'redirect_parameters' => [
                        'connection_id' => (string) $connection->id,
                    ],
                    'response_metadata' => [
                        'action' => $action,
                        'connection_id' => (string) $connection->id,
                        'status' => $status,
                        'lock_version' => (int) $connection->lock_version,
                        'current_version_id' => $connection->current_version_id === null
                            ? null
                            : (string) $connection->current_version_id,
                    ],
                ]);

                return $this->resourceResponse($connection, $status, $secret);
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isOperationUniqueViolation($exception)) {
                return $this->connectionFailure($exception);
            }

            try {
                $operation = $this->findOperation($actor, $key);
                if ($operation !== null) {
                    return $this->replayOperation($operation, $workspace, $fingerprint);
                }
            } catch (Throwable $replayException) {
                return $this->connectionFailure($replayException);
            }

            return $this->connectionFailure($exception);
        } catch (Throwable $exception) {
            return $this->connectionFailure($exception);
        }
    }

    private function connectionOperationKey(Request $request): string
    {
        $key = (string) $this->idempotencyKey($request, true);
        if (strlen($key) > 128) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'The idempotency key must be 128 characters or fewer.',
            ]);
        }

        return $key;
    }

    private function findOperation(User $actor, string $key): ?WorkspaceClientOperation
    {
        return WorkspaceClientOperation::query()
            ->where('initiating_user_id', $actor->id)
            ->where('client_operation_id', $key)
            ->lockForUpdate()
            ->first();
    }

    private function replayOperation(
        WorkspaceClientOperation $operation,
        Workspace $workspace,
        string $fingerprint,
    ): JsonResponse {
        if (! hash_equals((string) $operation->server_fingerprint, $fingerprint)) {
            throw new RuntimeException('operation_replay_mismatch');
        }

        $metadata = $operation->getAttribute('response_metadata');
        if (! is_array($metadata)) {
            $metadata = [];
        }
        $connectionId = (string) ($metadata['connection_id'] ?? '');
        $connection = ConversionSourceConnection::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($connectionId)
            ->with('currentVersion')
            ->first();
        if ($connection === null) {
            throw new RuntimeException('operation_replay_mismatch');
        }

        return $this->resourceResponse($connection, (int) ($metadata['status'] ?? 200));
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function operationFingerprint(
        string $action,
        int $workspaceId,
        ?string $connectionId,
        array $input,
        bool $secretProvided,
        ?string $providedSecret,
    ): string {
        $payload = [
            'action' => $action,
            'workspace_id' => $workspaceId,
            'connection_id' => $connectionId,
            'input' => $this->canonicalize($input),
            'secret' => $secretProvided
                ? ['sha256' => hash('sha256', (string) $providedSecret)]
                : ['generated' => true],
        ];

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function isOperationUniqueViolation(QueryException $exception): bool
    {
        return str_contains((string) $exception->getCode(), '23000')
            || str_contains($exception->getMessage(), 'workspace_client_ops_user_operation_unique')
            || str_contains($exception->getMessage(), 'workspace_client_operations.initiating_user_id');
    }

    private function connection(Request $request, string $id): ConversionSourceConnection
    {
        return ConversionSourceConnection::query()
            ->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey(strtolower($id))
            ->with('currentVersion')
            ->firstOrFail();
    }

    /** @param array<string,mixed> $data */
    private function assertExpectedLockVersion(ConversionSourceConnection $connection, array $data): void
    {
        if (array_key_exists('expected_lock_version', $data)
            && (int) $data['expected_lock_version'] !== (int) $connection->lock_version) {
            throw new RuntimeException('stale_version');
        }
    }

    /**
     * Preserve immutable connection policy when a rotation only supplies a
     * replacement secret. Explicit empty lists/nulls remain valid updates.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function options(array $data, ?ConversionSourceConnection $current = null): array
    {
        if (array_key_exists('definition_ids', $data) && ! array_key_exists('allowed_conversion_definition_ids', $data)) {
            $data['allowed_conversion_definition_ids'] = $data['definition_ids'];
        }
        if (array_key_exists('source_types', $data) && ! array_key_exists('allowed_source_types', $data)) {
            $data['allowed_source_types'] = $data['source_types'];
        }
        unset($data['definition_ids'], $data['source_types']);

        if ($current === null) {
            return $data;
        }

        $version = $current->relationLoaded('currentVersion')
            ? $current->currentVersion
            : $current->currentVersion()->first();
        if ($version === null) {
            throw ValidationException::withMessages(['connection' => 'The conversion connection has no active credential version.']);
        }

        return array_replace([
            'display_name' => (string) $current->display_name,
            'allowed_conversion_definition_ids' => (array) $version->allowed_conversion_definition_ids,
            'allowed_source_types' => (array) $version->allowed_source_types,
            'signature_algorithm' => (string) $version->signature_algorithm,
            'payload_limit_bytes' => (int) $version->payload_limit_bytes,
            'replay_window_seconds' => (int) $version->replay_window_seconds,
            'ip_policy' => $version->ip_policy,
            'rate_policy' => $version->rate_policy,
        ], $data);
    }

    private function connectionFailure(Throwable $exception): JsonResponse
    {
        if ($exception instanceof RuntimeException && $exception->getMessage() === 'conversion_connection_disabled') {
            return $this->errorResponse('conflict', 'The requested change conflicts with the current resource state.', 409, [
                'code' => 'conversion_connection_disabled',
            ]);
        }

        return $this->s2Failure($exception);
    }

    private function resourceResponse(ConversionSourceConnection $connection, int $status, ?string $secret = null): JsonResponse
    {
        $response = (new ConversionSourceConnectionResource($connection->load('currentVersion')))
            ->response()
            ->setStatusCode($status);
        $payload = $response->getData(true);
        if ($secret !== null) {
            $payload['data']['secret'] = $secret;
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response->setData($payload);
    }

    /** @param array<string,mixed> $extra */
    private function errorResponse(string $type, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'type' => $type,
                'message' => $message,
                ...$extra,
            ],
        ], $status);
    }
}
