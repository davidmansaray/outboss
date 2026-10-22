<?php

namespace App\Http\Controllers;

use App\Domain\Conversions\Services\InitializeMeetingMeasurement;
use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Exceptions\GtmPayloadValidationException;
use App\Domain\Gtm\Services\ChangeOfferStatus;
use App\Domain\Gtm\Services\EvaluateOfferReadiness;
use App\Domain\Gtm\Services\ReviewOfferVersion;
use App\Domain\Gtm\Services\SaveConsultationOffer;
use App\Models\Campaign;
use App\Models\ConversionDefinition;
use App\Models\Offer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class OfferSetupController extends Controller
{
    public function show(Request $request, Offer $offer): Response
    {
        $this->scoped($request, $offer);
        $offer->load(['currentVersion', 'currentReadinessEvaluation']);

        return Inertia::render('offers/Setup', [
            'return_campaign' => $this->returnCampaign($request)?->only(['id', 'name']),
            'offer' => [
                'id' => $offer->id, 'status' => $offer->status->value,
                'lock_version' => $offer->lock_version, 'version_id' => $offer->current_version_id,
                'version' => $offer->currentVersion->version, 'payload' => $offer->currentVersion->payload,
                'readiness' => $offer->currentReadinessEvaluation ? [
                    'state' => $offer->currentReadinessEvaluation->state->value,
                    'blockers' => $offer->currentReadinessEvaluation->blockers,
                ] : null,
            ],
            'definitions' => ConversionDefinition::query()->where('workspace_id', $offer->workspace_id)
                ->where('status', 'active')->with('currentVersion')->get()->map(fn ($definition) => [
                    'id' => $definition->current_version_id, 'name' => $definition->currentVersion?->display_name,
                ])->values(),
            'is_owner' => $request->user()->currentWorkspace()->isOwner($request->user()),
        ]);
    }

    public function save(Request $request, Offer $offer, SaveConsultationOffer $save): RedirectResponse
    {
        $this->scoped($request, $offer, true);
        try {
            $save->handle($offer, $request->user(), $request->all());
        } catch (GtmPayloadValidationException $error) {
            throw ValidationException::withMessages($error->errors);
        } catch (RuntimeException $error) {
            if (! in_array($error->getMessage(), ['stale_version', 'operation_replay_mismatch'], true)) {
                throw $error;
            }
            throw ValidationException::withMessages(['offer' => 'This Offer or save request changed. Reload before saving.']);
        }

        return $this->returnToSetup($request, $offer);
    }

    public function measurement(Request $request, Offer $offer, InitializeMeetingMeasurement $initialize): RedirectResponse
    {
        $this->scoped($request, $offer, true);
        // Explicit user action: create/activate the workspace's meeting measurement.
        $initialize->handle($request->user()->currentWorkspace(), $request->user());

        return $this->returnToSetup($request, $offer);
    }

    public function review(Request $request, Offer $offer, ReviewOfferVersion $review, ChangeOfferStatus $status, EvaluateOfferReadiness $readiness): RedirectResponse
    {
        $this->scoped($request, $offer, true);
        $data = $request->validate([
            'version_id' => ['required', 'uuid'], 'lock_version' => ['required', 'integer'],
            'action' => ['required', 'in:submit,approve,activate,pause,check'],
            'operation_id' => ['required', 'uuid'],
        ]);
        DB::transaction(function () use ($request, $offer, $data, $review, $status, $readiness): void {
            $locked = Offer::query()->where('workspace_id', $offer->workspace_id)->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if ($locked->current_version_id !== $data['version_id'] || $locked->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['offer' => 'The Offer changed. Reload and review the current version.']);
            }
            if (in_array($data['action'], ['activate', 'pause'], true)) {
                $status->handle($locked, $request->user(), $data['action'] === 'activate' ? OfferStatus::Active : OfferStatus::Paused, $locked->lock_version, $data['operation_id']);
            } elseif ($data['action'] === 'check') {
                $readiness->handle($locked, 'manual');
            } else {
                $review->handle($locked, $request->user(), $data['action']);
            }
        });

        return $this->returnToSetup($request, $offer);
    }

    private function scoped(Request $request, Offer $offer, bool $owner = false): void
    {
        abort_unless((int) $offer->workspace_id === (int) $request->user()->currentWorkspace()->id, 404);
        if ($owner) {
            abort_unless($request->user()->currentWorkspace()->isOwner($request->user()), 403);
        }
        $this->returnCampaign($request);
    }

    private function returnCampaign(Request $request): ?Campaign
    {
        if (! $request->has('from_campaign')) {
            return null;
        }
        $data = $request->validate(['from_campaign' => ['required', 'integer', 'min:1']]);
        $campaign = Campaign::query()->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey($data['from_campaign'])->firstOrFail();
        $this->authorize('view', $campaign);

        return $campaign;
    }

    private function returnToSetup(Request $request, Offer $offer): RedirectResponse
    {
        return redirect()->route('offers.setup', [
            'offer' => $offer, 'from_campaign' => $request->input('from_campaign'),
        ]);
    }
}
