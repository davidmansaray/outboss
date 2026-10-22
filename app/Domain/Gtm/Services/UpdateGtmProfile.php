<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Enums\GtmProfileVersionActor;
use App\Domain\Gtm\Enums\GtmProfileVersionOrigin;
use App\Domain\Gtm\Support\CanonicalGtmProfilePayload;
use App\Domain\Gtm\Support\GtmProfilePayloadValidator;
use App\Models\GtmProfile;
use App\Models\GtmProfileClientOperation;
use App\Models\GtmProfileVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Apply one owner-authored section patch to the immutable profile stream.
 */
final class UpdateGtmProfile
{
    /** @var list<string> */
    private const SECTIONS = ['company', 'market', 'buyers', 'messaging', 'qualification'];

    /** @var list<string> */
    private const REPEATED_FIELDS = [
        'geographies',
        'company_traits',
        'excluded_segments',
        'target_roles',
        'buying_triggers',
        'blocking_roles',
        'proof_points',
        'prohibited_claims',
        'message_constraints',
        'evidence_requirements',
        'business_disqualifiers',
    ];

    public function __construct(
        private readonly GtmProfilePayloadValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $patch
     */
    public function handle(
        GtmProfile $profile,
        User $actor,
        string $section,
        array $patch,
        int $expectedLockVersion,
        ?string $clientOperationId = null,
    ): GtmProfile {
        return $this->update($profile, $actor, $section, $patch, $expectedLockVersion, $clientOperationId);
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function update(
        GtmProfile $profile,
        User $actor,
        string $section,
        array $patch,
        int $expectedLockVersion,
        ?string $clientOperationId = null,
    ): GtmProfile {
        return $this->apply(
            $profile,
            $actor,
            $section,
            $patch,
            $expectedLockVersion,
            $clientOperationId,
            false,
        );
    }

    /**
     * The website coordinator is the only domain path allowed to include the
     * research target in a section mutation. It couples the version write to
     * run cancellation/supersession in one transaction.
     *
     * @param  array<string, mixed>  $patch
     */
    public function updateWebsite(
        GtmProfile $profile,
        User $actor,
        array $patch,
        int $expectedLockVersion,
        ?string $clientOperationId = null,
    ): GtmProfile {
        return $this->apply(
            $profile,
            $actor,
            'company',
            $patch,
            $expectedLockVersion,
            $clientOperationId,
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function apply(
        GtmProfile $profile,
        User $actor,
        string $section,
        array $patch,
        int $expectedLockVersion,
        ?string $clientOperationId,
        bool $dedicatedWebsiteAction,
    ): GtmProfile {
        $this->assertOperationId($clientOperationId);
        if (! in_array($section, self::SECTIONS, true)) {
            throw ValidationException::withMessages(['section' => 'This profile section is not supported.']);
        }
        if ($section === 'company' && array_key_exists('website_url', $patch) && ! $dedicatedWebsiteAction) {
            throw ValidationException::withMessages([
                'company.website_url' => 'Update the website using the dedicated website action.',
            ]);
        }

        $validatedPatch = $this->validator->validateSectionPatch($section, $patch);
        $clientOperationId ??= (string) Str::uuid();
        $fingerprint = $this->fingerprint($profile, $section, $validatedPatch, $expectedLockVersion);

        $operation = DB::transaction(function () use (
            $profile,
            $actor,
            $section,
            $validatedPatch,
            $expectedLockVersion,
            $clientOperationId,
            $fingerprint,
        ): GtmProfileClientOperation {
            $locked = GtmProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $this->assertOwner($locked, $actor);

            $operation = GtmProfileClientOperation::query()
                ->where('gtm_profile_id', $locked->id)
                ->where('client_operation_id', $clientOperationId)
                ->lockForUpdate()
                ->first();
            if ($operation !== null) {
                if (! hash_equals((string) $operation->server_fingerprint, $fingerprint)) {
                    throw new RuntimeException('operation_replay_mismatch');
                }

                // The request already committed. Nothing else may be applied
                // even if the caller's in-memory model is stale.
                return $operation;
            }

            if ((int) $locked->lock_version !== $expectedLockVersion) {
                throw new RuntimeException('stale_version');
            }

            $current = $this->currentVersion($locked);
            $currentPayload = $current->payload;
            $candidate = $this->mergeOwnerPatch($currentPayload, $section, $validatedPatch);
            $candidate = $this->validator->validate($candidate);

            $sameContent = CanonicalGtmProfilePayload::comparable($currentPayload)
                === CanonicalGtmProfilePayload::comparable($candidate);

            if ($sameContent) {
                return $this->recordOperation(
                    $locked,
                    $clientOperationId,
                    $fingerprint,
                    $expectedLockVersion,
                    (int) $locked->lock_version,
                    $current->id,
                    $current->id,
                    ['profile_id' => $locked->id, 'version' => $current->version, 'lock_version' => $locked->lock_version, 'no_op' => true],
                );

            }

            $version = $this->writeVersion($locked, $actor, $candidate, GtmProfileVersionOrigin::OwnerEdit);
            $newLockVersion = (int) $locked->lock_version + 1;
            $locked->forceFill([
                'current_version_id' => $version->id,
                'lock_version' => $newLockVersion,
            ])->save();

            return $this->recordOperation(
                $locked,
                $clientOperationId,
                $fingerprint,
                $expectedLockVersion,
                $newLockVersion,
                $current->id,
                $version->id,
                ['profile_id' => $locked->id, 'version' => $version->version, 'lock_version' => $newLockVersion, 'no_op' => false],
            );
        }, 3);

        return $this->resultForOperation($profile, $operation);
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function mergeOwnerPatch(array $current, string $section, array $patch): array
    {
        foreach ($patch as $field => $value) {
            if (in_array($field, self::REPEATED_FIELDS, true)) {
                $current[$section][$field] = $this->ownerRepeated(
                    is_array($value) ? $value : [],
                    is_array($current[$section][$field] ?? null) ? $current[$section][$field] : [],
                );
            } elseif (in_array($field, ['dimensions', 'review_min', 'contact_min'], true)) {
                // Qualification controls are typed contract values rather
                // than provenance-bearing descriptive fields.
                $current[$section][$field] = $value;
            } else {
                $raw = is_array($value) ? ($value['value'] ?? null) : $value;
                $current[$section][$field] = $this->ownerEnvelope($raw);
            }
        }

        return $current;
    }

    /**
     * @param  array<mixed>  $items
     * @param  array<mixed>  $existing
     * @return list<array<string, mixed>>
     */
    private function ownerRepeated(array $items, array $existing): array
    {
        $result = [];
        $matchedExisting = [];
        $seenSubmitted = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $raw = $item['value'] ?? null;
            $id = is_string($item['id'] ?? null) ? strtolower($item['id']) : null;
            $canonical = $this->canonicalValue($raw);
            $matched = null;
            $matchedIndex = null;
            foreach ($existing as $index => $old) {
                if (! is_array($old)) {
                    continue;
                }
                $oldRaw = $old['value'] ?? null;
                $oldId = is_string($old['id'] ?? null) ? strtolower($old['id']) : null;
                if ($id !== null && $oldId === $id) {
                    $matched = $old;
                    $matchedIndex = $index;
                    break;
                }
                if ($this->canonicalValue($oldRaw) === $canonical
                    && ! isset($matchedExisting[$index])) {
                    $matched = $old;
                    $matchedIndex = $index;
                    break;
                }
            }

            $resolvedId = is_array($matched) && is_string($matched['id'] ?? null)
                ? strtolower($matched['id'])
                : ($id ?? $this->stableItemId($raw));
            $dedupeKey = $resolvedId.'|'.$canonical;
            if (isset($seenSubmitted[$dedupeKey])) {
                continue;
            }
            $seenSubmitted[$dedupeKey] = true;

            if ($matchedIndex !== null) {
                $matchedExisting[$matchedIndex] = true;
            }

            // Supplying a previously tombstoned item is an explicit owner
            // action and may restore it. Research itself never gets this
            // path, so it cannot silently resurrect the item later.
            $result[] = $this->ownerRepeatedEnvelope(
                $raw,
                $resolvedId,
                ($item['tombstoned'] ?? false) === true,
            );
        }

        // A repeated-field patch is a replacement from the editor's point of
        // view. Keep omitted rows as locked tombstones instead of deleting
        // their identity; future research will then continue to ignore them.
        foreach ($existing as $index => $old) {
            if (! is_array($old) || isset($matchedExisting[$index])) {
                continue;
            }

            $oldId = is_string($old['id'] ?? null)
                ? strtolower($old['id'])
                : $this->stableItemId($old['value'] ?? null);
            $result[] = $this->ownerRepeatedEnvelope(
                $old['value'] ?? null,
                $oldId,
                true,
            );
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function ownerRepeatedEnvelope(mixed $value, string $id, bool $tombstoned): array
    {
        return [
            'id' => strtolower($id),
            'value' => $value,
            'provenance' => 'owner_supplied',
            'source_ids' => [],
            'owner_locked' => true,
            'updated_at' => now()->toIso8601String(),
            'tombstoned' => $tombstoned,
        ];
    }

    /** @return array{value: mixed, provenance: string, source_ids: list<string>, owner_locked: bool, updated_at: string} */
    private function ownerEnvelope(mixed $value): array
    {
        return [
            'value' => $value,
            'provenance' => 'owner_supplied',
            'source_ids' => [],
            'owner_locked' => true,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function currentVersion(GtmProfile $profile): GtmProfileVersion
    {
        $version = GtmProfileVersion::query()
            ->where('gtm_profile_id', $profile->id)
            ->whereKey($profile->current_version_id)
            ->lockForUpdate()
            ->first();

        if ($version === null) {
            throw new RuntimeException('profile_version_missing');
        }

        return $version;
    }

    /** @param array<string, mixed> $payload */
    private function writeVersion(
        GtmProfile $profile,
        User $actor,
        array $payload,
        GtmProfileVersionOrigin $origin,
    ): GtmProfileVersion {
        $latest = GtmProfileVersion::query()
            ->where('gtm_profile_id', $profile->id)
            ->orderByDesc('version')
            ->lockForUpdate()
            ->first();
        $nextVersion = $latest === null ? 1 : ((int) $latest->version + 1);

        return GtmProfileVersion::query()->create([
            'workspace_id' => $profile->workspace_id,
            'gtm_profile_id' => $profile->id,
            'version' => $nextVersion,
            'schema_version' => GtmProfileVersion::SCHEMA_VERSION,
            'payload' => $payload,
            'content_hash' => CanonicalGtmProfilePayload::hash($payload),
            'actor_type' => GtmProfileVersionActor::Owner,
            'actor_user_id' => $actor->id,
            'actor_display_name' => $actor->name,
            'origin' => $origin,
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function recordOperation(
        GtmProfile $profile,
        string $operationId,
        string $fingerprint,
        int $expectedLock,
        int $resultingLock,
        string $expectedVersion,
        string $resultingVersion,
        array $metadata,
    ): GtmProfileClientOperation {
        return GtmProfileClientOperation::query()->create([
            'gtm_profile_id' => $profile->id,
            'client_operation_id' => $operationId,
            'server_fingerprint' => $fingerprint,
            'expected_lock_version' => $expectedLock,
            'resulting_lock_version' => $resultingLock,
            'expected_version_id' => $expectedVersion,
            'resulting_version_id' => $resultingVersion,
            'response_metadata' => $metadata,
        ]);
    }

    /**
     * Reconstruct the response that was committed for this operation. A
     * replay must not borrow the aggregate's newer lock or version, otherwise
     * a caller can mistake somebody else's later write for its own saved
     * result and send another edit against the wrong base.
     */
    private function resultForOperation(
        GtmProfile $profile,
        GtmProfileClientOperation $operation,
    ): GtmProfile {
        $result = GtmProfile::query()->whereKey($profile->id)->firstOrFail();
        $version = GtmProfileVersion::query()
            ->where('gtm_profile_id', $profile->id)
            ->whereKey($operation->resulting_version_id)
            ->firstOrFail();

        $result->forceFill([
            'current_version_id' => $version->id,
            'lock_version' => (int) $operation->resulting_lock_version,
        ]);
        $result->setRelation('currentVersion', $version);

        return $result;
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(GtmProfile $profile, string $section, array $payload, int $lockVersion): string
    {
        return CanonicalGtmProfilePayload::hash([
            'aggregate' => 'gtm_profile',
            'aggregate_id' => (string) $profile->id,
            'action' => 'update_section',
            'section' => $section,
            'expected_lock_version' => $lockVersion,
            'payload' => $payload,
        ]);
    }

    private function assertOwner(GtmProfile $profile, User $actor): void
    {
        $workspace = $profile->workspace()->first();
        if ($workspace === null || ! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can edit the GTM profile.');
        }
    }

    private function assertOperationId(?string $operationId): void
    {
        if ($operationId !== null && (trim($operationId) === '' || strlen($operationId) > 128)) {
            throw ValidationException::withMessages(['client_operation_id' => 'The client operation ID is invalid.']);
        }
    }

    private function stableItemId(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hex = substr(hash('sha256', 'gtm-profile-item|'.($encoded === false ? '' : $encoded)), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    private function canonicalValue(mixed $value): string
    {
        if (is_string($value)) {
            return mb_strtolower((string) preg_replace('/\s+/', ' ', trim($value)));
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
