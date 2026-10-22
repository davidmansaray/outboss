<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Enums\OfferReadinessState;
use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Enums\OfferVersionOrigin;
use App\Domain\Gtm\Services\Concerns\OfferMutationSupport;
use App\Models\Offer;
use App\Models\OfferVersionReviewEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ChangeOfferStatus
{
    use OfferMutationSupport;

    public function __construct(private readonly EvaluateOfferReadiness $readiness) {}

    public function handle(
        Offer $offer,
        User $actor,
        OfferStatus|string $status,
        int $expectedLockVersion,
        ?string $clientOperationId = null,
    ): Offer {
        $target = $this->resolveStatus($status);
        $operationId = $this->operationId($clientOperationId);
        $fingerprint = $this->fingerprint(
            'change_status',
            (string) $offer->id,
            [],
            $target,
            $expectedLockVersion,
        );

        return DB::transaction(function () use (
            $offer,
            $actor,
            $target,
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
            $currentStatus = $this->offerStatus($locked);

            if ($currentStatus === $target) {
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
                        'status' => $currentStatus->value,
                        'no_op' => true,
                    ],
                );

                return $this->operationResult($locked, $operation);
            }

            if (! $currentStatus->canTransitionTo($target)) {
                throw ValidationException::withMessages([
                    'status' => "An Offer cannot move from {$currentStatus->value} to {$target->value}.",
                ]);
            }

            $payload = (array) $current->payload;
            if ($target === OfferStatus::Active) {
                $this->assertActivationReady($locked, $payload, $current);
                $this->lockOfferWorkspace($locked);
                $this->assertUniqueActiveName($locked, $payload);
            }

            $version = $this->writeVersion(
                $locked,
                $actor,
                $payload,
                $target,
                OfferVersionOrigin::StatusChange,
                $currentStatus,
            );
            $newLockVersion = (int) $locked->lock_version + 1;
            $locked->forceFill([
                'current_version_id' => $version->id,
                'status' => $target,
                'active_name_normalized' => $target === OfferStatus::Active
                    ? $this->normalizedOfferName($payload)
                    : null,
                'lock_version' => $newLockVersion,
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
                    'status' => $target->value,
                    'from_status' => $currentStatus->value,
                    'no_op' => false,
                ],
            );

            if ((int) ($version->schema_version ?? 1) === 2) {
                $priorApproval = OfferVersionReviewEvent::query()
                    ->where('workspace_id', $locked->workspace_id)
                    ->where('offer_id', $locked->id)
                    ->where('offer_version_id', $current->id)
                    ->where('to_state', OfferReadinessState::Approved->value)
                    ->exists();
                if ($priorApproval) {
                    OfferVersionReviewEvent::query()->create([
                        'workspace_id' => $locked->workspace_id,
                        'offer_id' => $locked->id,
                        'offer_version_id' => $version->id,
                        'action' => 'lifecycle_carry',
                        'from_state' => OfferReadinessState::Approved,
                        'to_state' => OfferReadinessState::Approved,
                        'actor_user_id' => $actor->id,
                        'actor_display_name' => $actor->name,
                        'reason' => 'Approval carried forward because only the aggregate lifecycle changed.',
                        'created_at' => now(),
                    ]);
                }
                $this->readiness->handle($locked, 'lifecycle_'.$target->value);
                $locked->refresh();
            }

            return $this->operationResult($locked, $operation);
        }, 3);
    }

    public function change(
        Offer $offer,
        User $actor,
        OfferStatus|string $status,
        int $expectedLockVersion,
        ?string $clientOperationId = null,
    ): Offer {
        return $this->handle($offer, $actor, $status, $expectedLockVersion, $clientOperationId);
    }

    private function resolveStatus(OfferStatus|string $status): OfferStatus
    {
        if ($status instanceof OfferStatus) {
            return $status;
        }

        $resolved = OfferStatus::tryFrom($status);
        if ($resolved === null) {
            throw ValidationException::withMessages(['status' => 'The Offer status is invalid.']);
        }

        return $resolved;
    }
}
