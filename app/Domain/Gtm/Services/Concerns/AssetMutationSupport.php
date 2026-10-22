<?php

namespace App\Domain\Gtm\Services\Concerns;

use App\Domain\Gtm\Support\CanonicalAssetPayload;
use App\Domain\Gtm\Support\CanonicalOfferPayload;
use App\Models\Asset;
use App\Models\AssetClientOperation;
use App\Models\AssetVersion;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Shared validation and immutable-version helpers for Asset mutations. */
trait AssetMutationSupport
{
    private function assertOwner(Asset $asset, User $actor): void
    {
        $workspace = Workspace::query()->whereKey($asset->workspace_id)->first();

        if ($workspace === null || ! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can change Assets.');
        }
    }

    private function operationId(?string $operationId): ?string
    {
        if ($operationId === null) {
            return null;
        }

        $operationId = trim($operationId);
        if ($operationId === '' || strlen($operationId) > 160) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Provide an operation key of at most 160 characters.',
            ]);
        }

        return $operationId;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function assetPayload(array $input, ?AssetVersion $base = null): array
    {
        $value = static function (string $field, mixed $fallback = null) use ($input, $base): mixed {
            if (array_key_exists($field, $input)) {
                return $input[$field];
            }
            if ($base !== null) {
                return $base->getAttribute($field);
            }

            return $fallback;
        };

        $title = $value('title', '');
        $description = $value('description', '');
        $url = $value('external_url', '');
        $mediaType = $value('media_type', '');
        $source = $value('source', 'owner');
        $licence = $value('licence');

        if (! is_string($title) || trim($title) === '' || mb_strlen(trim($title)) > 160) {
            throw ValidationException::withMessages(['title' => 'Provide an Asset title of at most 160 characters.']);
        }
        if (! is_string($description) || mb_strlen($description) > 5000) {
            throw ValidationException::withMessages(['description' => 'Asset descriptions must be at most 5000 characters.']);
        }
        $url = is_string($url) ? trim($url) : '';
        $host = parse_url($url, PHP_URL_HOST);
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || ! is_string($host) || $host === ''
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
            || strlen($url) > 2048) {
            throw ValidationException::withMessages(['external_url' => 'Assets require a bounded HTTPS URL.']);
        }
        if (! is_string($mediaType) || trim($mediaType) === '' || mb_strlen(trim($mediaType)) > 120) {
            throw ValidationException::withMessages(['media_type' => 'Provide a valid media type.']);
        }
        if (! is_string($source) || mb_strlen(trim($source)) > 160) {
            throw ValidationException::withMessages(['source' => 'Asset sources must be at most 160 characters.']);
        }
        if ($licence !== null && (! is_string($licence) || mb_strlen(trim($licence)) > 160)) {
            throw ValidationException::withMessages(['licence' => 'Asset licences must be at most 160 characters.']);
        }

        $approval = $value('approval_state', 'draft');
        if (! is_string($approval) || ! in_array($approval, ['draft', 'approved'], true)) {
            throw ValidationException::withMessages(['approval_state' => 'Asset approval state is invalid.']);
        }
        $availability = $value('availability_state', 'available');
        if (! is_string($availability) || ! in_array($availability, ['available', 'unavailable'], true)) {
            throw ValidationException::withMessages(['availability_state' => 'Asset availability state is invalid.']);
        }
        $classification = $value('classification', 'public');
        if (! is_string($classification) || ! in_array($classification, ['public', 'internal', 'restricted'], true)) {
            throw ValidationException::withMessages(['classification' => 'Asset classification is invalid.']);
        }

        $expiresAt = $this->assetExpiry($value('expires_at'));
        $payload = [
            'title' => trim($title),
            'description' => trim($description),
            'external_url' => $url,
            'media_type' => trim($mediaType),
            'source' => trim($source) !== '' ? trim($source) : null,
            'access_instructions' => $this->boundedAssetArray($value('access_instructions', []), 'access_instructions'),
            'approval_state' => $approval,
            'intended_audience' => $this->boundedAssetArray($value('intended_audience', []), 'intended_audience'),
            'expires_at' => $expiresAt,
            'availability_state' => $availability,
            'licence' => $licence === null || trim($licence) === '' ? null : trim($licence),
            'classification' => $classification,
            'delivery_restrictions' => $this->boundedAssetArray($value('delivery_restrictions', []), 'delivery_restrictions'),
            'tracking_metadata' => $this->boundedAssetArray($value('tracking_metadata', []), 'tracking_metadata'),
        ];
        if (strlen(CanonicalAssetPayload::json($payload)) > 256_000) {
            throw ValidationException::withMessages(['asset' => 'The Asset metadata exceeds the bounded size limit.']);
        }

        return $payload;
    }

    private function assetExpiry(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }
        if (! is_string($value)) {
            throw ValidationException::withMessages(['expires_at' => 'Asset expiry must be a valid date.']);
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            throw ValidationException::withMessages(['expires_at' => 'Asset expiry must be a valid date.']);
        }
    }

    /** @return array<string|int,mixed> */
    private function boundedAssetArray(mixed $value, string $field): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || count($value) > 20) {
            throw ValidationException::withMessages([$field => 'Asset metadata lists must contain at most 20 items.']);
        }

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => 'Asset metadata must be valid JSON.']);
        }
        if (strlen($encoded) > 64_000) {
            throw ValidationException::withMessages([$field => 'Asset metadata exceeds the bounded size limit.']);
        }

        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function fingerprint(string $action, string $assetId, array $payload, int $expectedLockVersion): string
    {
        return hash('sha256', CanonicalOfferPayload::json([
            'aggregate' => 'asset',
            'aggregate_id' => $assetId,
            'action' => $action,
            'payload' => $payload,
            'expected_lock_version' => $expectedLockVersion,
        ]));
    }

    /** @param array<string,mixed> $payload */
    private function writeVersion(Asset $asset, User $actor, array $payload): AssetVersion
    {
        $latest = AssetVersion::query()
            ->where('workspace_id', $asset->workspace_id)
            ->where('asset_id', $asset->id)
            ->orderByDesc('version')
            ->lockForUpdate()
            ->first();
        $nextVersion = $latest === null ? 1 : (int) $latest->version + 1;

        return AssetVersion::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $asset->workspace_id,
            'asset_id' => $asset->id,
            'version' => $nextVersion,
            ...$payload,
            'content_hash' => CanonicalAssetPayload::hash($payload),
            'actor_user_id' => $actor->id,
            'actor_display_name' => $actor->name,
            'created_at' => now(),
        ]);
    }

    private function existingOperation(Asset $asset, string $operationId): ?AssetClientOperation
    {
        return AssetClientOperation::query()
            ->where('workspace_id', $asset->workspace_id)
            ->where('asset_id', $asset->id)
            ->where('client_operation_id', $operationId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordOperation(
        Asset $asset,
        string $operationId,
        string $fingerprint,
        ?int $expectedLockVersion,
        int $resultingLockVersion,
        ?string $expectedVersionId,
        string $resultingVersionId,
        array $metadata,
    ): AssetClientOperation {
        return AssetClientOperation::query()->create([
            'workspace_id' => $asset->workspace_id,
            'asset_id' => $asset->id,
            'client_operation_id' => $operationId,
            'server_fingerprint' => $fingerprint,
            'expected_lock_version' => $expectedLockVersion,
            'resulting_lock_version' => $resultingLockVersion,
            'expected_version_id' => $expectedVersionId,
            'resulting_version_id' => $resultingVersionId,
            'response_metadata' => $metadata,
        ]);
    }

    /**
     * Rebuild the aggregate state produced by the original operation. A
     * later mutation must not change an idempotent replay response.
     */
    private function operationResult(Asset $asset, ?AssetClientOperation $operation = null): Asset
    {
        if ($operation === null) {
            return $asset->fresh(['currentVersion']) ?? $asset->load('currentVersion');
        }

        $version = AssetVersion::query()
            ->where('workspace_id', $asset->workspace_id)
            ->where('asset_id', $asset->id)
            ->whereKey($operation->resulting_version_id)
            ->first();
        if ($version === null || $operation->resulting_lock_version === null) {
            throw new \RuntimeException('asset_operation_result_missing');
        }

        $metadata = $operation->response_metadata;
        $status = is_array($metadata) && is_string($metadata['status'] ?? null)
            ? $metadata['status']
            : $asset->getRawOriginal('status');
        $result = clone $asset;
        $result->forceFill([
            'current_version_id' => $version->id,
            'status' => $status,
            'lock_version' => (int) $operation->resulting_lock_version,
            'updated_at' => $version->created_at,
        ]);
        $result->setRelation('currentVersion', $version);

        return $result;
    }
}
