<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Enums\OfferVersionOrigin;
use App\Domain\Gtm\Services\Concerns\OfferMutationSupport;
use App\Domain\Gtm\Support\OfferPayloadValidator;
use App\Models\Offer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CreateOffer
{
    use OfferMutationSupport;

    public function __construct(
        private readonly OfferPayloadValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        Workspace $workspace,
        User $actor,
        array $payload,
        ?string $clientOperationId = null,
    ): Offer {
        $this->assertWorkspaceOwner($workspace, $actor);
        $validatedPayload = $this->validator->validate($payload);
        $operationId = $this->operationId($clientOperationId);
        $fingerprint = $this->fingerprint(
            'create',
            (string) $workspace->id,
            $validatedPayload,
            OfferStatus::Draft,
            0,
        );
        $offerId = $this->operationOfferId($workspace, $operationId);

        return DB::transaction(function () use (
            $workspace,
            $actor,
            $validatedPayload,
            $operationId,
            $fingerprint,
            $offerId,
        ): Offer {
            $existing = Offer::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($offerId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $operation = $this->existingOperation($existing, $operationId);
                if ($operation === null) {
                    throw new RuntimeException('offer_create_result_conflict');
                }
                if (! hash_equals((string) $operation->server_fingerprint, $fingerprint)) {
                    throw new RuntimeException('operation_replay_mismatch');
                }

                return $this->operationResult($existing, $operation);
            }

            $offer = Offer::query()->create([
                'id' => $offerId,
                'workspace_id' => $workspace->id,
                'current_version_id' => null,
                'status' => OfferStatus::Draft,
                'lock_version' => 0,
            ]);
            $version = $this->writeVersion(
                $offer,
                $actor,
                $validatedPayload,
                OfferStatus::Draft,
                OfferVersionOrigin::Created,
            );
            $offer->forceFill(['current_version_id' => $version->id])->save();

            $operation = $this->recordOperation(
                $offer,
                $operationId,
                $fingerprint,
                null,
                0,
                null,
                $version->id,
                [
                    'offer_id' => $offer->id,
                    'version' => $version->version,
                    'lock_version' => 0,
                    'status' => OfferStatus::Draft->value,
                    'no_op' => false,
                ],
            );

            return $this->operationResult($offer, $operation);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(
        Workspace $workspace,
        User $actor,
        array $payload,
        ?string $clientOperationId = null,
    ): Offer {
        return $this->handle($workspace, $actor, $payload, $clientOperationId);
    }

    private function operationOfferId(Workspace $workspace, string $operationId): string
    {
        $hex = substr(hash('sha256', 'offer-create|'.$workspace->id.'|'.$operationId), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
