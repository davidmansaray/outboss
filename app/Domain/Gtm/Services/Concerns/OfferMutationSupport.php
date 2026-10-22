<?php

namespace App\Domain\Gtm\Services\Concerns;

use App\Domain\Gtm\Enums\OfferReadinessState;
use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Enums\OfferVersionOrigin;
use App\Domain\Gtm\Support\CanonicalOfferPayload;
use App\Models\Offer;
use App\Models\OfferClientOperation;
use App\Models\OfferReadinessEvaluation;
use App\Models\OfferVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait OfferMutationSupport
{
    private function assertOwner(Offer $offer, User $actor): void
    {
        $workspace = Workspace::query()->whereKey($offer->workspace_id)->first();

        if ($workspace === null || ! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can change Offers.');
        }
    }

    private function assertWorkspaceOwner(Workspace $workspace, User $actor): void
    {
        if (! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can change Offers.');
        }
    }

    /**
     * Serialize active-name checks for every Offer in a workspace.
     *
     * Offer names live in immutable JSON versions, so a conventional unique
     * database index cannot express the active-name rule. Locking the stable
     * workspace row in the same transaction makes the check authoritative for
     * concurrent activations and active-content edits.
     */
    private function lockOfferWorkspace(Offer $offer): Workspace
    {
        return Workspace::query()
            ->whereKey($offer->workspace_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function operationId(?string $operationId): string
    {
        if ($operationId === null) {
            return (string) Str::uuid();
        }

        $operationId = trim($operationId);
        if ($operationId === '' || strlen($operationId) > 128) {
            throw ValidationException::withMessages([
                'client_operation_id' => 'The client operation ID is invalid.',
            ]);
        }

        return $operationId;
    }

    private function currentVersion(Offer $offer): OfferVersion
    {
        $version = OfferVersion::query()
            ->where('offer_id', $offer->id)
            ->whereKey($offer->current_version_id)
            ->lockForUpdate()
            ->first();

        if ($version === null) {
            throw new \RuntimeException('offer_version_missing');
        }

        return $version;
    }

    private function offerStatus(Offer $offer): OfferStatus
    {
        $status = $offer->getAttribute('status');

        if ($status instanceof OfferStatus) {
            return $status;
        }

        $resolved = OfferStatus::tryFrom((string) $status);
        if ($resolved === null) {
            throw ValidationException::withMessages(['status' => 'The Offer status is invalid.']);
        }

        return $resolved;
    }

    /** @param array<string, mixed> $payload */
    private function assertActivationReady(Offer $offer, array $payload, ?OfferVersion $version = null): void
    {
        $version ??= $this->currentVersion($offer);
        if ((int) ($version->schema_version ?? 1) !== 2) {
            $errors = [];
            foreach (['name', 'promise', 'target_audience', 'desired_outcome', 'call_to_action'] as $field) {
                $value = $payload[$field] ?? null;
                if (! is_string($value) || trim($value) === '') {
                    $errors[$field] = 'Complete this field before activating the Offer.';
                }
            }

            if ($errors !== []) {
                $errors['status'] = 'Complete the required Offer content before activating it.';
                throw ValidationException::withMessages($errors);
            }

            return;
        }

        $evaluation = $offer->current_readiness_evaluation_id !== null
            ? OfferReadinessEvaluation::query()
                ->where('workspace_id', $offer->workspace_id)
                ->where('offer_id', $offer->id)
                ->where('offer_version_id', $version->id)
                ->whereKey($offer->current_readiness_evaluation_id)
                ->first()
            : null;
        if ($offer->availability_state !== OfferReadinessState::Approved
            || $evaluation?->state !== OfferReadinessState::Approved
            || ($evaluation->valid_until !== null && $evaluation->valid_until->isPast())) {
            throw ValidationException::withMessages([
                'status' => 'Approve a complete operational Offer Version with no readiness blockers before activation.',
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertUniqueActiveName(Offer $offer, array $payload): void
    {
        $normalized = $this->normalizedOfferName($payload);
        if ($normalized === null) {
            return;
        }

        $duplicate = Offer::query()
            ->where('workspace_id', $offer->workspace_id)
            ->where('active_name_normalized', $normalized)
            ->where('id', '!=', $offer->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => 'An active Offer with this name already exists in the workspace.',
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function normalizedOfferName(array $payload): ?string
    {
        $name = $payload['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return mb_strtolower(trim($name));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(
        string $action,
        string $offerId,
        array $payload,
        OfferStatus|string|null $status,
        int $expectedLockVersion,
    ): string {
        return CanonicalOfferPayload::hash([
            'aggregate' => 'offer',
            'aggregate_id' => $offerId,
            'action' => $action,
            'payload' => $payload,
            'status' => $status instanceof OfferStatus ? $status->value : $status,
            'expected_lock_version' => $expectedLockVersion,
        ]);
    }

    private function existingOperation(Offer $offer, string $operationId): ?OfferClientOperation
    {
        return OfferClientOperation::query()
            ->where('offer_id', $offer->id)
            ->where('client_operation_id', $operationId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeVersion(
        Offer $offer,
        User $actor,
        array $payload,
        OfferStatus $status,
        OfferVersionOrigin $origin,
        ?OfferStatus $fromStatus = null,
    ): OfferVersion {
        $latest = OfferVersion::query()
            ->where('offer_id', $offer->id)
            ->orderByDesc('version')
            ->lockForUpdate()
            ->first();
        $nextVersion = $latest === null ? 1 : (int) $latest->version + 1;

        return OfferVersion::query()->create([
            'workspace_id' => $offer->workspace_id,
            'offer_id' => $offer->id,
            'version' => $nextVersion,
            'schema_version' => CanonicalOfferPayload::schemaVersion($payload),
            'payload' => $payload,
            'status_snapshot' => $status,
            'content_hash' => CanonicalOfferPayload::hash($payload, $status),
            'origin' => $origin,
            'from_status' => $fromStatus,
            'actor_type' => 'owner',
            'actor_user_id' => $actor->id,
            'actor_display_name' => $actor->name,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordOperation(
        Offer $offer,
        string $operationId,
        string $fingerprint,
        ?int $expectedLockVersion,
        int $resultingLockVersion,
        ?string $expectedVersionId,
        string $resultingVersionId,
        array $metadata,
    ): OfferClientOperation {
        return OfferClientOperation::query()->create([
            'offer_id' => $offer->id,
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
     * Rebuild the aggregate state produced by the original operation instead
     * of leaking a later mutation into an idempotent replay response.
     */
    private function operationResult(Offer $offer, OfferClientOperation $operation): Offer
    {
        $versionId = $operation->resulting_version_id;
        $version = is_string($versionId) && $versionId !== ''
            ? OfferVersion::query()
                ->where('offer_id', $offer->id)
                ->whereKey($versionId)
                ->first()
            : null;

        if ($version === null || $operation->resulting_lock_version === null) {
            throw new \RuntimeException('offer_operation_result_missing');
        }

        $metadata = $operation->response_metadata;
        $status = is_array($metadata) && is_string($metadata['status'] ?? null)
            ? OfferStatus::tryFrom($metadata['status'])
            : null;
        $result = clone $offer;
        $result->forceFill([
            'current_version_id' => $version->id,
            'status' => $status ?? $version->status_snapshot,
            'lock_version' => (int) $operation->resulting_lock_version,
            'updated_at' => $version->created_at,
        ]);
        $result->setRelation('currentVersion', $version);

        return $result;
    }
}
