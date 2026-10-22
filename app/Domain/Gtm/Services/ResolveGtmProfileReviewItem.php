<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Enums\GtmProfileReviewStatus;
use App\Domain\Gtm\Enums\GtmProfileReviewType;
use App\Domain\Gtm\Enums\GtmProfileVersionActor;
use App\Domain\Gtm\Enums\GtmProfileVersionOrigin;
use App\Domain\Gtm\Support\CanonicalGtmProfilePayload;
use App\Domain\Gtm\Support\GtmProfilePayloadValidator;
use App\Models\GtmProfile;
use App\Models\GtmProfileClientOperation;
use App\Models\GtmProfileReviewItem;
use App\Models\GtmProfileVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Resolve one review item while preserving the profile's owner locks and
 * immutable version history.
 */
final class ResolveGtmProfileReviewItem
{
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

    /** @var list<string> */
    private const ACTIONS = ['accept_research', 'keep_owner', 'edit_owner', 'maintain', 'let_outboss_maintain'];

    /** @var array<string, list<string>> */
    private const ACTIONS_BY_TYPE = [
        'missing' => ['edit_owner'],
        'conflict' => ['accept_research', 'keep_owner', 'edit_owner', 'maintain', 'let_outboss_maintain'],
        'inferred' => ['accept_research', 'keep_owner', 'edit_owner', 'maintain', 'let_outboss_maintain'],
        'stale' => ['accept_research', 'keep_owner', 'edit_owner', 'maintain', 'let_outboss_maintain'],
    ];

    public function __construct(
        private readonly GtmProfilePayloadValidator $validator,
    ) {}

    /**
     * @param  array<mixed>|null  $editedValue
     */
    public function handle(
        GtmProfileReviewItem $item,
        User $actor,
        string $action,
        ?array $editedValue = null,
        int $expectedLockVersion = 0,
        ?string $clientOperationId = null,
    ): GtmProfile {
        return $this->resolve($item, $actor, $action, $editedValue, $expectedLockVersion, $clientOperationId);
    }

    /**
     * @param  array<mixed>|null  $editedValue
     */
    public function resolve(
        GtmProfileReviewItem $item,
        User $actor,
        string $action,
        ?array $editedValue = null,
        int $expectedLockVersion = 0,
        ?string $clientOperationId = null,
    ): GtmProfile {
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => 'This review action is not supported.']);
        }
        if ($clientOperationId === null) {
            $clientOperationId = (string) Str::uuid();
        } elseif (trim($clientOperationId) === '' || strlen($clientOperationId) > 128) {
            throw ValidationException::withMessages(['client_operation_id' => 'The client operation ID is invalid.']);
        }

        $fingerprint = CanonicalGtmProfilePayload::hash([
            'aggregate' => 'gtm_profile',
            'aggregate_id' => (string) $item->gtm_profile_id,
            'action' => 'resolve_review_item',
            'review_item_id' => (string) $item->id,
            'review_action' => $action,
            'edited_value' => $editedValue,
            'expected_lock_version' => $expectedLockVersion,
        ]);

        $operation = DB::transaction(function () use (
            $item,
            $actor,
            $action,
            $editedValue,
            $expectedLockVersion,
            $clientOperationId,
            $fingerprint,
        ): GtmProfileClientOperation {
            $review = GtmProfileReviewItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $profile = GtmProfile::query()->whereKey($review->gtm_profile_id)->lockForUpdate()->firstOrFail();
            $this->assertOwner($profile, $actor);

            $operation = GtmProfileClientOperation::query()
                ->where('gtm_profile_id', $profile->id)
                ->where('client_operation_id', $clientOperationId)
                ->lockForUpdate()
                ->first();
            if ($operation !== null) {
                if (! hash_equals((string) $operation->server_fingerprint, $fingerprint)) {
                    throw new RuntimeException('operation_replay_mismatch');
                }

                return $operation;
            }

            if ((int) $profile->lock_version !== $expectedLockVersion) {
                throw new RuntimeException('stale_version');
            }
            $reviewStatus = GtmProfileReviewStatus::tryFrom((string) $review->getRawOriginal('status'));
            if ($reviewStatus !== GtmProfileReviewStatus::Open) {
                throw new RuntimeException('review_item_already_resolved');
            }
            $this->assertActionAllowed($review, $action, $editedValue);

            $version = $this->currentVersion($profile);
            $currentPayload = $version->payload;
            $fieldPath = (string) $review->field_path;
            [$section, $field] = $this->fieldPath($fieldPath);
            $candidate = $currentPayload;
            $newVersion = null;

            if ($action === 'accept_research') {
                $candidate = $this->withResearchValue(
                    $candidate,
                    $section,
                    $field,
                    $review->proposed_value,
                    $this->sourceIds($review),
                );
            } elseif ($action === 'edit_owner') {
                if ($editedValue === null) {
                    throw ValidationException::withMessages(['edited_value' => 'An edited value is required.']);
                }
                $candidate = $this->withOwnerValue($candidate, $section, $field, $editedValue);
            } elseif ($action === 'maintain' || $action === 'let_outboss_maintain') {
                $candidate = $this->clearOwnerLock($candidate, $section, $field);
            }

            $candidate = $this->validator->validate($candidate);
            $sameContent = CanonicalGtmProfilePayload::comparable($currentPayload)
                === CanonicalGtmProfilePayload::comparable($candidate);

            if (! in_array($action, ['keep_owner'], true) && ! $sameContent) {
                $newVersion = $this->writeVersion($profile, $actor, $candidate);
                $profile->forceFill([
                    'current_version_id' => $newVersion->id,
                    'lock_version' => (int) $profile->lock_version + 1,
                ])->save();
            }

            $resolvedValue = $this->fieldValue($candidate, $section, $field);
            $resolvedVersionId = $newVersion !== null ? $newVersion->id : $version->id;
            $review->forceFill([
                'status' => GtmProfileReviewStatus::Resolved,
                'resolved_value' => is_array($resolvedValue) ? $resolvedValue : ['value' => $resolvedValue],
                'resolved_version_id' => $resolvedVersionId,
                'resolved_by_user_id' => $actor->id,
                'resolved_at' => now(),
            ])->save();

            if (in_array($action, ['edit_owner', 'maintain', 'let_outboss_maintain'], true)) {
                $this->resolveOtherFieldItems($profile, $fieldPath, $actor, $resolvedValue, $resolvedVersionId);
            }

            $resultingLock = (int) $profile->lock_version;

            return $this->recordOperation(
                $profile,
                $clientOperationId,
                $fingerprint,
                $expectedLockVersion,
                $resultingLock,
                $version->id,
                $resolvedVersionId,
                [
                    'profile_id' => $profile->id,
                    'review_item_id' => $review->id,
                    'action' => $action,
                    'version' => $newVersion !== null ? $newVersion->version : $version->version,
                    'lock_version' => $resultingLock,
                    'no_op' => $newVersion === null,
                ],
            );
        }, 3);

        return $this->resultForOperation($item, $operation);
    }

    /** @return array{0: string, 1: string} */
    private function fieldPath(string $path): array
    {
        $parts = explode('.', $path);
        if (count($parts) !== 2 || ! in_array($parts[0], ['company', 'market', 'buyers', 'messaging', 'qualification'], true)) {
            throw ValidationException::withMessages(['field_path' => 'This review field is not supported.']);
        }

        return [(string) $parts[0], (string) $parts[1]];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<mixed>  $sourceIds
     * @return array<string, mixed>
     */
    private function withResearchValue(
        array $payload,
        string $section,
        string $field,
        mixed $proposed,
        array $sourceIds,
    ): array {
        if (in_array($field, self::REPEATED_FIELDS, true)) {
            $payload[$section][$field] = $this->mergeAcceptedRepeatedItems(
                is_array($payload[$section][$field] ?? null)
                    ? $payload[$section][$field]
                    : [],
                $proposed,
                $this->validSourceIds($sourceIds),
            );
        } elseif (in_array($field, ['dimensions', 'review_min', 'contact_min'], true)) {
            $payload[$section][$field] = $proposed;
        } else {
            $payload[$section][$field] = [
                'value' => $this->rawValue($proposed),
                'provenance' => 'ai_inferred',
                'source_ids' => $this->validSourceIds($sourceIds),
                'owner_locked' => false,
                'updated_at' => now()->toIso8601String(),
            ];
        }

        return $payload;
    }

    /**
     * Accept only the proposal represented by this review item. A review is
     * not a replacement editor for the whole repeated field: unrelated
     * owner values, prior research values, tombstones, and stable IDs must
     * remain byte-for-byte intact.
     *
     * @param  array<mixed>  $current
     * @param  list<string>  $sourceIds
     * @return list<array<string, mixed>>
     */
    private function mergeAcceptedRepeatedItems(
        array $current,
        mixed $proposed,
        array $sourceIds,
    ): array {
        $result = array_values(array_filter(
            $current,
            static fn (mixed $item): bool => is_array($item),
        ));
        $proposals = is_array($proposed) && array_is_list($proposed)
            ? $proposed
            : [$proposed];
        $accepted = [];

        foreach ($proposals as $proposal) {
            $raw = $this->rawValue($proposal);
            $canonical = $this->canonicalValue($raw);
            if ($canonical === '' || isset($accepted[$canonical])) {
                continue;
            }
            $accepted[$canonical] = true;

            $proposalId = is_array($proposal) && $this->validUuid($proposal['id'] ?? null)
                ? strtolower((string) $proposal['id'])
                : null;
            $matchedIndex = null;

            foreach ($result as $index => $existing) {
                $existingId = $this->validUuid($existing['id'] ?? null)
                    ? strtolower((string) $existing['id'])
                    : null;
                if (($proposalId !== null && $proposalId === $existingId)
                    || $this->canonicalValue($existing['value'] ?? null) === $canonical) {
                    $matchedIndex = $index;
                    break;
                }
            }

            if ($matchedIndex !== null) {
                $existing = $result[$matchedIndex];
                $existingId = $this->validUuid($existing['id'] ?? null)
                    ? strtolower((string) $existing['id'])
                    : $this->stableItemId($raw);
                $result[$matchedIndex] = [
                    ...$existing,
                    'id' => $existingId,
                    'value' => $raw,
                    'provenance' => 'ai_inferred',
                    'source_ids' => array_values(array_unique([
                        ...$this->validSourceIds(
                            is_array($existing['source_ids'] ?? null)
                                ? array_values($existing['source_ids'])
                                : [],
                        ),
                        ...$sourceIds,
                    ])),
                    'owner_locked' => false,
                    'updated_at' => now()->toIso8601String(),
                    // Accepting a proposal that matches a tombstone is the
                    // explicit owner action required to restore that item.
                    'tombstoned' => false,
                ];

                continue;
            }

            // Review proposals never get to choose persistent identity. New
            // rows use the same deterministic server identity as research.
            $result[] = [
                'id' => $this->stableItemId($raw),
                'value' => $raw,
                'provenance' => 'ai_inferred',
                'source_ids' => $sourceIds,
                'owner_locked' => false,
                'updated_at' => now()->toIso8601String(),
                'tombstoned' => false,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<mixed>  $editedValue
     * @return array<string, mixed>
     */
    private function withOwnerValue(array $payload, string $section, string $field, array $editedValue): array
    {
        $raw = $this->rawValue($editedValue);
        if (in_array($field, self::REPEATED_FIELDS, true)) {
            $items = array_is_list($editedValue) ? $editedValue : [$editedValue];
            $payload[$section][$field] = array_map(function (mixed $item): array {
                $itemRaw = $this->rawValue($item);

                return [
                    'value' => $itemRaw,
                    'provenance' => 'owner_supplied',
                    'source_ids' => [],
                    'owner_locked' => true,
                    'updated_at' => now()->toIso8601String(),
                    'tombstoned' => is_array($item) && ($item['tombstoned'] ?? false) === true,
                ];
            }, $items);
        } elseif (in_array($field, ['dimensions', 'review_min', 'contact_min'], true)) {
            $payload[$section][$field] = $raw;
        } else {
            $payload[$section][$field] = [
                'value' => $raw,
                'provenance' => 'owner_supplied',
                'source_ids' => [],
                'owner_locked' => true,
                'updated_at' => now()->toIso8601String(),
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function clearOwnerLock(array $payload, string $section, string $field): array
    {
        $value = $payload[$section][$field] ?? null;
        if (in_array($field, self::REPEATED_FIELDS, true)) {
            if (is_array($value)) {
                foreach ($value as $index => $item) {
                    if (is_array($item)) {
                        $value[$index]['provenance'] = 'ai_inferred';
                        $value[$index]['owner_locked'] = false;
                        $value[$index]['updated_at'] = now()->toIso8601String();
                    }
                }
            }
        } elseif (is_array($value)) {
            $value['provenance'] = 'ai_inferred';
            $value['owner_locked'] = false;
            $value['updated_at'] = now()->toIso8601String();
            $payload[$section][$field] = $value;
        }
        $payload[$section][$field] = $value;

        return $payload;
    }

    private function rawValue(mixed $value): mixed
    {
        return is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
    }

    private function canonicalValue(mixed $value): string
    {
        if (is_string($value)) {
            return mb_strtolower((string) preg_replace('/\s+/', ' ', trim($value)));
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    private function stableItemId(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hex = substr(hash('sha256', 'gtm-profile-item|'.($encoded === false ? '' : $encoded)), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    private function validUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * @param  list<mixed>  $sourceIds
     * @return list<string>
     */
    private function validSourceIds(array $sourceIds): array
    {
        return array_values(array_filter($sourceIds, static fn (mixed $sourceId): bool => is_string($sourceId)
            && preg_match('/^[0-9a-fA-F-]{36}$/', $sourceId) === 1));
    }

    /** @return list<mixed> */
    private function sourceIds(GtmProfileReviewItem $review): array
    {
        $sourceIds = $review->getAttribute('source_ids');

        return is_array($sourceIds) ? array_values($sourceIds) : [];
    }

    /** @param array<string, mixed> $payload */
    private function fieldValue(array $payload, string $section, string $field): mixed
    {
        return $payload[$section][$field] ?? null;
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
    private function writeVersion(GtmProfile $profile, User $actor, array $payload): GtmProfileVersion
    {
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
            'origin' => GtmProfileVersionOrigin::ReviewResolution,
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
     * Rebuild the exact profile snapshot acknowledged by the original
     * operation. A replay must not borrow a later owner's lock/version and
     * accidentally authorize a queued edit against somebody else's write.
     */
    private function resultForOperation(
        GtmProfileReviewItem $item,
        GtmProfileClientOperation $operation,
    ): GtmProfile {
        $result = GtmProfile::query()->whereKey($item->gtm_profile_id)->firstOrFail();
        $version = GtmProfileVersion::query()
            ->where('gtm_profile_id', $item->gtm_profile_id)
            ->whereKey($operation->resulting_version_id)
            ->firstOrFail();

        $result->forceFill([
            'current_version_id' => $version->id,
            'lock_version' => (int) $operation->resulting_lock_version,
        ]);
        $result->setRelation('currentVersion', $version);

        return $result;
    }

    /** @param array<mixed>|null $editedValue */
    private function assertActionAllowed(
        GtmProfileReviewItem $review,
        string $action,
        ?array $editedValue,
    ): void {
        $type = GtmProfileReviewType::tryFrom((string) $review->getRawOriginal('type'));
        if ($type === null) {
            throw ValidationException::withMessages(['action' => 'This review type is not supported.']);
        }

        if (! in_array($action, self::ACTIONS_BY_TYPE[$type->value], true)) {
            throw ValidationException::withMessages([
                'action' => 'This action is not available for this review question.',
            ]);
        }

        if ($action === 'accept_research' && $review->proposed_value === null) {
            throw ValidationException::withMessages([
                'action' => 'This review question does not have a research proposal to accept.',
            ]);
        }

        if ($action === 'edit_owner' && $editedValue === null) {
            throw ValidationException::withMessages(['edited_value' => 'An edited value is required.']);
        }

        if ($type === GtmProfileReviewType::Missing
            && $action === 'edit_owner'
            && ! $this->hasAnswer($editedValue)) {
            throw ValidationException::withMessages(['edited_value' => 'Enter an answer for this question.']);
        }
    }

    /** @param array<mixed>|null $editedValue */
    private function hasAnswer(?array $editedValue): bool
    {
        if ($editedValue === null) {
            return false;
        }

        $values = array_is_list($editedValue) ? $editedValue : [$editedValue];

        foreach ($values as $value) {
            $raw = $this->rawValue($value);
            if (is_string($raw) && trim($raw) !== '') {
                return true;
            }
            if ($raw !== null && ! is_string($raw)) {
                return true;
            }
        }

        return false;
    }

    private function assertOwner(GtmProfile $profile, User $actor): void
    {
        $workspace = $profile->workspace()->first();
        if ($workspace === null || ! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can resolve GTM profile reviews.');
        }
    }

    private function resolveOtherFieldItems(
        GtmProfile $profile,
        string $fieldPath,
        User $actor,
        mixed $resolvedValue,
        string $resolvedVersionId,
    ): void {
        GtmProfileReviewItem::query()
            ->where('gtm_profile_id', $profile->id)
            ->where('field_path', $fieldPath)
            ->where('status', GtmProfileReviewStatus::Open)
            ->lockForUpdate()
            ->each(function (GtmProfileReviewItem $other) use ($actor, $resolvedValue, $resolvedVersionId): void {
                $other->forceFill([
                    'status' => GtmProfileReviewStatus::Resolved,
                    'resolved_value' => is_array($resolvedValue) ? $resolvedValue : ['value' => $resolvedValue],
                    'resolved_version_id' => $resolvedVersionId,
                    'resolved_by_user_id' => $actor->id,
                    'resolved_at' => now(),
                ])->save();
            });
    }
}
