<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Support\CanonicalAssetPayload;
use App\Domain\Gtm\Support\CanonicalOfferPayload;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateAsset
{
    /** @param array<string, mixed> $input */
    public function handle(Workspace $workspace, User $actor, array $input, ?string $operationId = null): Asset
    {
        if (! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can create Assets.');
        }

        $title = trim((string) ($input['title'] ?? ''));
        $url = trim((string) ($input['external_url'] ?? ''));
        $mediaType = trim((string) ($input['media_type'] ?? ''));
        if ($title === '' || mb_strlen($title) > 160) {
            throw ValidationException::withMessages(['title' => 'Provide an Asset title of at most 160 characters.']);
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https' || strlen($url) > 2048) {
            throw ValidationException::withMessages(['external_url' => 'Assets require a bounded HTTPS URL.']);
        }
        if ($mediaType === '' || mb_strlen($mediaType) > 120) {
            throw ValidationException::withMessages(['media_type' => 'Provide a valid media type.']);
        }
        $operationId = $this->operationId($operationId);
        $operationHash = hash('sha256', CanonicalOfferPayload::json($input));

        return DB::transaction(function () use ($workspace, $actor, $input, $title, $url, $mediaType, $operationId, $operationHash): Asset {
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if ($operationId !== null) {
                $existing = Asset::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('client_operation_id', $operationId)
                    ->first();
                if ($existing !== null) {
                    if (! hash_equals((string) $existing->operation_hash, $operationHash)) {
                        throw new \RuntimeException('operation_replay_mismatch');
                    }

                    return $existing->load('currentVersion');
                }
            }

            $asset = Asset::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'status' => 'active',
                'lock_version' => 0,
                'client_operation_id' => $operationId,
                'operation_hash' => $operationHash,
                'created_by_user_id' => $actor->id,
            ]);
            $payload = [
                'title' => $title,
                'description' => trim((string) ($input['description'] ?? '')),
                'external_url' => $url,
                'media_type' => $mediaType,
                'source' => trim((string) ($input['source'] ?? 'owner')),
                'access_instructions' => $this->array($input['access_instructions'] ?? []),
                'approval_state' => ($input['approval_state'] ?? null) === 'approved' ? 'approved' : 'draft',
                'intended_audience' => $this->array($input['intended_audience'] ?? []),
                'expires_at' => $input['expires_at'] ?? null,
                'availability_state' => ($input['availability_state'] ?? null) === 'unavailable' ? 'unavailable' : 'available',
                'licence' => trim((string) ($input['licence'] ?? '')) ?: null,
                'classification' => in_array($input['classification'] ?? null, ['public', 'internal', 'restricted'], true)
                    ? $input['classification'] : 'public',
                'delivery_restrictions' => $this->array($input['delivery_restrictions'] ?? []),
                'tracking_metadata' => $this->array($input['tracking_metadata'] ?? []),
            ];
            $version = AssetVersion::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'asset_id' => $asset->id,
                'version' => 1,
                ...$payload,
                'content_hash' => CanonicalAssetPayload::hash($payload),
                'actor_user_id' => $actor->id,
                'actor_display_name' => $actor->name,
                'created_at' => now(),
            ]);
            $asset->forceFill(['current_version_id' => $version->id])->save();

            return $asset->load('currentVersion');
        }, 3);
    }

    /** @return array<string, mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function operationId(?string $operationId): ?string
    {
        $operationId = $operationId === null ? null : trim($operationId);
        if ($operationId !== null && ($operationId === '' || strlen($operationId) > 160)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Provide an operation key of at most 160 characters.']);
        }

        return $operationId;
    }
}
