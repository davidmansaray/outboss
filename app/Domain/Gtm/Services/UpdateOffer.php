<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Enums\OfferVersionOrigin;
use App\Domain\Gtm\Services\Concerns\OfferMutationSupport;
use App\Domain\Gtm\Support\CanonicalOfferPayload;
use App\Domain\Gtm\Support\OfferPayloadValidator;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class UpdateOffer
{
    use OfferMutationSupport;

    public function __construct(
        private readonly OfferPayloadValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        Offer $offer,
        User $actor,
        array $payload,
        int $expectedLockVersion,
        ?string $clientOperationId = null,
    ): Offer {
        $validatedPayload = $this->validator->validate($payload);
        $operationId = $this->operationId($clientOperationId);
        $fingerprint = $this->fingerprint(
            'update',
            (string) $offer->id,
            $validatedPayload,
            null,
            $expectedLockVersion,
        );

        return DB::transaction(function () use (
            $offer,
            $actor,
            $validatedPayload,
            $expectedLockVersion,
            $operationId,
            $fingerprint,
        ): Offer {
            $locked = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $this->assertOwner($locked, $actor);

            $operation = $this->existingOperation($locked, $operationId);
            if ($operation !== null) {
                if (! hash_equals((string) $operation->server_fingerprint, $fingerprint)) {
                    throw new RuntimeException('operation_replay_mismatch');
                }

                return $this->operationResult($locked, $operation);
            }

            if ((int) $locked->lock_version !== $expectedLockVersion) {
                throw new RuntimeException('stale_version');
            }

            $current = $this->currentVersion($locked);
            $status = $this->offerStatus($locked);
            $sameContent = CanonicalOfferPayload::comparable((array) $current->payload)
                === CanonicalOfferPayload::comparable($validatedPayload);

            if ($sameContent) {
                $operation = $this->recordOperation(
                    $locked,
                    $operationId,
                    $fingerprint,
                    $expectedLockVersion,
                    (int) $locked->lock_version,
                    $current->id,
                    $current->id,
                    [
                        'offer_id' => $locked->id,
                        'version' => $current->version,
                        'lock_version' => $locked->lock_version,
                        'status' => $status->value,
                        'no_op' => true,
                    ],
                );

                return $this->operationResult($locked, $operation);
            }

            if ($status === OfferStatus::Active) {
                if (CanonicalOfferPayload::schemaVersion((array) $current->payload) === 2
                    || CanonicalOfferPayload::schemaVersion($validatedPayload) === 2) {
                    throw ValidationException::withMessages([
                        'status' => 'Pause this Offer before changing its operational contract.',
                    ]);
                }
                $this->assertActivationReady($locked, $validatedPayload, $current);
                $this->lockOfferWorkspace($locked);
                $this->assertUniqueActiveName($locked, $validatedPayload);
            }

            $version = $this->writeVersion(
                $locked,
                $actor,
                $validatedPayload,
                $status,
                OfferVersionOrigin::OwnerEdit,
            );
            $newLockVersion = (int) $locked->lock_version + 1;
            $locked->forceFill([
                'current_version_id' => $version->id,
                'lock_version' => $newLockVersion,
                'active_name_normalized' => $status === OfferStatus::Active
                    ? $this->normalizedOfferName($validatedPayload)
                    : null,
                ...(CanonicalOfferPayload::schemaVersion($validatedPayload) === 2 ? [
                    'availability_state' => 'incomplete',
                    'current_readiness_evaluation_id' => null,
                ] : []),
            ])->save();

            $operation = $this->recordOperation(
                $locked,
                $operationId,
                $fingerprint,
                $expectedLockVersion,
                $newLockVersion,
                $current->id,
                $version->id,
                [
                    'offer_id' => $locked->id,
                    'version' => $version->version,
                    'lock_version' => $newLockVersion,
                    'status' => $status->value,
                    'no_op' => false,
                ],
            );

            return $this->operationResult($locked, $operation);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(
        Offer $offer,
        User $actor,
        array $payload,
        int $expectedLockVersion,
        ?string $clientOperationId = null,
    ): Offer {
        return $this->handle($offer, $actor, $payload, $expectedLockVersion, $clientOperationId);
    }
}
