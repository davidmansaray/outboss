<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Conversions\Enums\ConversionDefinitionStatus;
use App\Domain\Gtm\Enums\FulfilmentMode;
use App\Domain\Gtm\Enums\OfferReadinessState;
use App\Domain\Gtm\Support\CanonicalOfferPayload;
use App\Models\AssetVersion;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use App\Models\FulfilmentCapability;
use App\Models\Offer;
use App\Models\OfferReadinessEvaluation;
use App\Models\OfferVersion;
use App\Models\OfferVersionReviewEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EvaluateOfferReadiness
{
    public const VERSION = 1;

    public function handle(Offer $offer, string $trigger = 'manual'): OfferReadinessEvaluation
    {
        return DB::transaction(function () use ($offer, $trigger): OfferReadinessEvaluation {
            $locked = Offer::query()
                ->where('workspace_id', $offer->workspace_id)
                ->whereKey($offer->id)
                ->lockForUpdate()
                ->firstOrFail();
            $version = OfferVersion::query()
                ->where('workspace_id', $locked->workspace_id)
                ->where('offer_id', $locked->id)
                ->whereKey($locked->current_version_id)
                ->firstOrFail();

            [$state, $blockers, $dependencies, $validUntil] = $this->assess($locked, $version);
            $evaluation = OfferReadinessEvaluation::query()->create([
                'workspace_id' => $locked->workspace_id,
                'offer_id' => $locked->id,
                'offer_version_id' => $version->id,
                'evaluator_version' => self::VERSION,
                'state' => $state,
                'blockers' => $blockers,
                'dependency_snapshot' => $dependencies,
                'dependency_hash' => hash('sha256', CanonicalOfferPayload::json($dependencies)),
                'trigger' => mb_substr($trigger, 0, 32),
                'evaluated_at' => now(),
                'valid_until' => $validUntil,
                'created_at' => now(),
            ]);
            $locked->forceFill([
                'availability_state' => $state,
                'current_readiness_evaluation_id' => $evaluation->id,
            ])->save();

            return $evaluation;
        }, 3);
    }

    /**
     * @return array{OfferReadinessState, list<array{code:string,message:string}>, array<string,mixed>, CarbonInterface|null}
     */
    public function assess(Offer $offer, OfferVersion $version): array
    {
        $payload = $version->payload;
        $blockers = [];
        $dependencies = [
            'offer_version_id' => (string) $version->id,
            'offer_content_hash' => (string) $version->content_hash,
            'schema_version' => (int) ($version->schema_version ?? 1),
        ];
        $expiry = null;

        if ((int) ($version->schema_version ?? 1) !== 2 || ($payload['schema_version'] ?? null) !== 2) {
            $this->block($blockers, 'offer_schema_incomplete', 'Complete an operational Offer Version before review.');
        } else {
            $authority = $payload['commercial_authority']['approved'] ?? false;
            if ($authority !== true) {
                $this->block($blockers, 'commercial_authority_missing', 'Commercial authority must be approved.');
            }
            if (! is_array($payload['approved_claims'] ?? null) || $payload['approved_claims'] === []) {
                $this->block($blockers, 'approved_claims_missing', 'Approve at least one claim supporting the Offer promise.');
            }

            $expiryValue = $payload['expires_at'] ?? null;
            if (is_string($expiryValue) && $expiryValue !== '') {
                $expiry = Carbon::parse($expiryValue);
                $dependencies['expires_at'] = $expiry->toIso8601String();
                if ($expiry->isPast()) {
                    $this->block($blockers, 'offer_expired', 'The Offer Version has expired.');
                }
            }

            $definitionVersionId = $payload['cta']['acceptance_conversion_definition_version_id'] ?? null;
            $definitionVersion = is_string($definitionVersionId)
                ? ConversionDefinitionVersion::query()
                    ->where('workspace_id', $offer->workspace_id)
                    ->whereKey($definitionVersionId)
                    ->first()
                : null;
            $definition = $definitionVersion instanceof ConversionDefinitionVersion
                ? ConversionDefinition::query()
                    ->where('workspace_id', $offer->workspace_id)
                    ->whereKey($definitionVersion->conversion_definition_id)
                    ->first()
                : null;
            $dependencies['conversion_definition_version_id'] = $definitionVersionId;
            $definitionStatus = $definition?->getAttribute('status');
            $definitionStatus = $definitionStatus instanceof ConversionDefinitionStatus
                ? $definitionStatus
                : ConversionDefinitionStatus::tryFrom((string) $definitionStatus);
            $dependencies['conversion_definition_status'] = $definitionStatus?->value;
            if ($definitionVersion === null || $definition === null || $definitionStatus !== ConversionDefinitionStatus::Active) {
                $this->block($blockers, 'conversion_definition_unavailable', 'Use an active workspace Conversion Definition Version.');
            }

            $mode = FulfilmentMode::tryFrom((string) ($payload['fulfilment']['mode'] ?? ''));
            $dependencies['fulfilment_mode'] = $mode?->value;
            if ($mode === FulfilmentMode::ExternalDestination) {
                $destination = $payload['cta']['destination_url'] ?? null;
                if (! is_string($destination) || filter_var($destination, FILTER_VALIDATE_URL) === false || parse_url($destination, PHP_URL_SCHEME) !== 'https') {
                    $this->block($blockers, 'cta_destination_unavailable', 'External fulfilment requires an approved HTTPS destination.');
                } else {
                    $dependencies['destination_hash'] = hash('sha256', $destination);
                }
            } elseif ($mode === FulfilmentMode::ExistingAsset) {
                $ids = $payload['fulfilment']['asset_version_ids'] ?? [];
                $ids = is_array($ids) ? array_values(array_unique(array_filter($ids, 'is_string'))) : [];
                $assets = AssetVersion::query()
                    ->where('workspace_id', $offer->workspace_id)
                    ->whereIn('id', $ids)
                    ->get();
                $dependencies['asset_version_ids'] = $ids;
                if ($ids === [] || $assets->count() !== count($ids)) {
                    $this->block($blockers, 'asset_version_missing', 'Existing-asset fulfilment requires exact workspace Asset Versions.');
                }
                foreach ($assets as $asset) {
                    if ($asset->approval_state !== 'approved' || $asset->availability_state !== 'available' || ($asset->expires_at !== null && $asset->expires_at->isPast())) {
                        $this->block($blockers, 'asset_version_unavailable', 'Every deliverable Asset Version must be approved, available and current.');
                        break;
                    }
                }
            } elseif ($mode === FulfilmentMode::HumanAssisted) {
                $capabilityId = $payload['fulfilment']['capability_id'] ?? null;
                $capability = is_string($capabilityId)
                    ? FulfilmentCapability::query()
                        ->where('workspace_id', $offer->workspace_id)
                        ->whereKey($capabilityId)
                        ->first()
                    : null;
                $dependencies['fulfilment_capability_id'] = $capabilityId;
                $dependencies['fulfilment_capability_version'] = $capability?->configuration_version;
                if ($capability === null || $capability->mode !== FulfilmentMode::HumanAssisted || $capability->status !== 'active' || $capability->health_state !== 'healthy') {
                    $this->block($blockers, 'fulfilment_capability_unavailable', 'Human-assisted fulfilment requires a healthy active capability.');
                } elseif ($capability->owner_user_id === null || $capability->response_sla_hours === null || $capability->capacity_limit === null || $capability->capacity_window === null) {
                    $this->block($blockers, 'human_fulfilment_incomplete', 'Configure owner, SLA and capacity before approval.');
                }
            } else {
                $this->block($blockers, 'fulfilment_mode_missing', 'Choose a supported fulfilment mode.');
            }
        }

        $latestReview = OfferVersionReviewEvent::query()
            ->where('workspace_id', $offer->workspace_id)
            ->where('offer_id', $offer->id)
            ->where('offer_version_id', $version->id)
            ->latest('created_at')
            ->latest('id')
            ->first();
        $reviewState = $latestReview?->to_state;
        $reviewState = $reviewState instanceof OfferReadinessState ? $reviewState : null;

        if (collect($blockers)->contains(fn (array $blocker): bool => $blocker['code'] === 'offer_expired')) {
            $state = OfferReadinessState::Expired;
        } elseif ($blockers !== []) {
            $state = $latestReview !== null || $offer->status->value === 'active'
                ? OfferReadinessState::Blocked
                : OfferReadinessState::Incomplete;
        } elseif ($reviewState === OfferReadinessState::Approved) {
            $state = OfferReadinessState::Approved;
        } elseif ($reviewState === OfferReadinessState::Blocked) {
            $state = OfferReadinessState::Blocked;
        } elseif ($reviewState === OfferReadinessState::Incomplete) {
            $state = OfferReadinessState::Incomplete;
        } else {
            $state = OfferReadinessState::ReadyForReview;
        }

        $dependencies['review_state'] = $reviewState?->value;
        $validUntil = now()->addMinutes(15);
        if ($expiry !== null && $expiry->lessThan($validUntil)) {
            $validUntil = $expiry;
        }

        return [$state, $blockers, $dependencies, $validUntil];
    }

    /** @param list<array{code:string,message:string}> $blockers */
    private function block(array &$blockers, string $code, string $message): void
    {
        $blockers[] = compact('code', 'message');
    }
}
