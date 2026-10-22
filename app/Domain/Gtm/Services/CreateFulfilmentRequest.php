<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Enums\FulfilmentMode;
use App\Domain\Gtm\Enums\OfferReadinessState;
use App\Domain\Gtm\Enums\OfferStatus;
use App\Models\ConversionEvent;
use App\Models\FulfilmentCapability;
use App\Models\FulfilmentCapacityReservation;
use App\Models\FulfilmentCapacityWindow;
use App\Models\FulfilmentRequest;
use App\Models\Offer;
use App\Models\OfferVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateFulfilmentRequest
{
    public function handle(Offer $offer, ConversionEvent $event): ?FulfilmentRequest
    {
        return DB::transaction(function () use ($offer, $event): ?FulfilmentRequest {
            $lockedOffer = Offer::query()
                ->where('workspace_id', $event->workspace_id)
                ->whereKey($offer->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (
                $event->matched_account_id === null
                || $lockedOffer->status !== OfferStatus::Active
                || $lockedOffer->availability_state !== OfferReadinessState::Approved
            ) {
                return null;
            }
            $version = OfferVersion::query()
                ->where('workspace_id', $lockedOffer->workspace_id)
                ->where('offer_id', $lockedOffer->id)
                ->whereKey($lockedOffer->current_version_id)
                ->firstOrFail();
            $payload = $version->payload;
            $mode = FulfilmentMode::tryFrom((string) ($payload['fulfilment']['mode'] ?? ''));
            if ($mode !== FulfilmentMode::HumanAssisted) {
                return null;
            }
            if (($payload['cta']['acceptance_conversion_definition_version_id'] ?? null) !== $event->conversion_definition_version_id) {
                return null;
            }

            $idempotencyKey = hash('sha256', implode('|', [
                $lockedOffer->workspace_id,
                $version->id,
                $event->id,
                $event->matched_account_id,
            ]));
            $existing = FulfilmentRequest::query()
                ->where('workspace_id', $lockedOffer->workspace_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $capabilityId = $payload['fulfilment']['capability_id'] ?? null;
            $capability = FulfilmentCapability::query()
                ->where('workspace_id', $lockedOffer->workspace_id)
                ->whereKey($capabilityId)
                ->lockForUpdate()
                ->first();
            if ($capability === null || $capability->status !== 'active' || $capability->health_state !== 'healthy' || $capability->capacity_limit === null) {
                throw ValidationException::withMessages(['fulfilment' => 'The human-assisted fulfilment capability is unavailable.']);
            }

            $windowKey = $this->windowKey((string) $capability->capacity_window);
            FulfilmentCapacityWindow::query()->firstOrCreate(
                [
                    'workspace_id' => $lockedOffer->workspace_id,
                    'fulfilment_capability_id' => $capability->id,
                    'window_key' => $windowKey,
                ],
                [
                    'limit_units' => $capability->capacity_limit,
                    'reserved_units' => 0,
                    'consumed_units' => 0,
                ],
            );
            $window = FulfilmentCapacityWindow::query()
                ->where('workspace_id', $lockedOffer->workspace_id)
                ->where('fulfilment_capability_id', $capability->id)
                ->where('window_key', $windowKey)
                ->lockForUpdate()
                ->firstOrFail();
            $units = max(1, (int) ($payload['fulfilment']['capacity_units'] ?? 1));
            if ($window->reserved_units + $window->consumed_units + $units > $window->limit_units) {
                throw ValidationException::withMessages(['fulfilment' => 'No fulfilment capacity remains in the current window.']);
            }

            $request = FulfilmentRequest::query()->create([
                'workspace_id' => $lockedOffer->workspace_id,
                'offer_id' => $lockedOffer->id,
                'offer_version_id' => $version->id,
                'conversion_event_id' => $event->id,
                'account_id' => $event->matched_account_id,
                'fulfilment_capability_id' => $capability->id,
                'idempotency_key' => $idempotencyKey,
                'required_inputs' => is_array($payload['cta']['required_inputs'] ?? null) ? $payload['cta']['required_inputs'] : [],
                'due_at' => $capability->response_sla_hours !== null ? now()->addHours($capability->response_sla_hours) : null,
                'assigned_user_id' => $capability->owner_user_id,
                'state' => 'pending',
            ]);
            FulfilmentCapacityReservation::query()->create([
                'workspace_id' => $lockedOffer->workspace_id,
                'fulfilment_request_id' => $request->id,
                'fulfilment_capability_id' => $capability->id,
                'capacity_window_id' => $window->id,
                'units' => $units,
                'state' => 'active',
                'reserved_at' => now(),
            ]);
            $window->increment('reserved_units', $units);

            return $request;
        }, 3);
    }

    private function windowKey(string $window): string
    {
        $now = Carbon::now('UTC');

        return match ($window) {
            'day' => 'day:'.$now->format('Y-m-d'),
            'month' => 'month:'.$now->format('Y-m'),
            default => 'week:'.$now->startOfWeek()->format('Y-m-d'),
        };
    }
}
