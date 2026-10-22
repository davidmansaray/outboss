<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\Prospecting\Enums\ExternalIdentityState;
use App\Domain\Prospecting\Enums\MembershipState;
use App\Domain\Prospecting\Support\ObservationRedactor;
use App\Models\AccountSourceMembership;
use App\Models\ExternalIdentity;
use App\Models\PersonSourceMembership;
use App\Models\ProspectSource;
use App\Models\SourceObservation;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class ExpireSourceObservations
{
    public function __construct(private readonly ObservationRedactor $redactor) {}

    public function handle(?Workspace $workspace = null, ?ProspectSource $source = null): int
    {
        // A source-scoped direct call must also scope the independent identity
        // sweep to that source's workspace. Without this fallback, callers
        // passing only a source could mutate due identities in other tenants.
        $identityWorkspace = $workspace;
        if ($identityWorkspace === null && $source !== null) {
            $identityWorkspace = Workspace::query()->find($source->workspace_id);
        }

        $query = SourceObservation::query()
            ->whereNotNull('retain_until')
            ->where('retain_until', '<=', now())
            ->whereNull('expired_at');
        if ($workspace !== null) {
            $query->where('workspace_id', $workspace->id);
        }
        if ($source !== null) {
            $query->where('prospect_source_id', $source->id);
        }

        $expired = 0;
        $query->orderBy('id')->chunkById(100, function ($observations) use (&$expired): void {
            foreach ($observations as $observation) {
                DB::transaction(function () use ($observation, &$expired): void {
                    $observation = SourceObservation::query()->whereKey($observation->id)->lockForUpdate()->first();
                    $retainUntil = $observation?->getAttribute('retain_until');
                    if ($observation === null || $observation->expired_at !== null || ! $retainUntil instanceof CarbonInterface || $retainUntil->isFuture()) {
                        return;
                    }
                    $this->redactor->expire($observation);
                    $this->refreshExternalIdentities($observation);
                    $this->rebuildAccountMemberships($observation);
                    $this->rebuildPersonMemberships($observation);
                    $expired++;
                }, 3);
            }
        });

        // External identities have their own retention deadline. An identity
        // may outlive the source observation that first introduced it, so
        // enforce due identity retention independently of the observation
        // sweep as well.
        $this->expireDueExternalIdentities($identityWorkspace);

        return $expired;
    }

    private function refreshExternalIdentities(SourceObservation $observation): void
    {
        ExternalIdentity::query()
            ->where('workspace_id', $observation->workspace_id)
            ->where(function ($query) use ($observation): void {
                $query->where('first_source_observation_id', $observation->id)
                    ->orWhere('last_source_observation_id', $observation->id);
            })
            ->lockForUpdate()
            ->get()
            ->each(fn (ExternalIdentity $identity): ExternalIdentity => $this->refreshExternalIdentity($identity));
    }

    private function refreshExternalIdentity(ExternalIdentity $identity): ExternalIdentity
    {
        $entityColumn = $identity->entity_type === 'account' ? 'account_id' : 'person_id';
        $identityColumn = $identity->identity_type === 'provider_record_id'
            ? 'provider_record_id'
            : 'canonical_url';
        $active = SourceObservation::query()
            ->where('workspace_id', $identity->workspace_id)
            ->where($entityColumn, $identity->{$entityColumn})
            ->whereNull('expired_at')
            ->where(function ($query): void {
                $query->whereNull('retain_until')
                    ->orWhere('retain_until', '>', now());
            })
            ->whereNotNull($identityColumn)
            ->with('version')
            ->orderBy('retrieved_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (SourceObservation $candidate): bool => $this->matchesExternalIdentity(
                $identity,
                $candidate,
                $identityColumn,
            ))
            ->values();

        $latest = $active->last();
        if ($latest instanceof SourceObservation) {
            $identity->forceFill([
                'value' => (string) $latest->{$identityColumn},
                'last_source_observation_id' => $latest->id,
                'last_observed_at' => $latest->retrieved_at,
                'policy_hash' => $latest->policy_hash,
                'retain_until' => $latest->retain_until,
                'state' => ExternalIdentityState::Active,
            ])->save();

            return $identity->fresh();
        }

        $retainUntil = $identity->getAttribute('retain_until');
        if ($retainUntil instanceof CarbonInterface && ! $retainUntil->isFuture() && $identity->value !== null) {
            // Keep the durable value_hash row so a later observation can still
            // resolve collisions, but remove the reversible provider value
            // once no retained observation authorises keeping it.
            $identity->forceFill([
                'value' => null,
                'state' => ExternalIdentityState::Restricted,
            ])->save();
        }

        return $identity->fresh();
    }

    private function matchesExternalIdentity(ExternalIdentity $identity, SourceObservation $observation, string $identityColumn): bool
    {
        $identityType = $observation->provider_record_id !== null
            ? 'provider_record_id'
            : 'canonical_url';
        $configuration = $observation->version?->getAttribute('configuration');
        $provider = (string) (is_array($configuration) ? ($configuration['provider'] ?? null) : null);
        $provider = $provider !== '' ? $provider : 'source:'.$observation->prospect_source_id;

        return $identityType === (string) $identity->identity_type
            && $provider === (string) $identity->provider
            && hash_equals(
                (string) $identity->value_hash,
                hash('sha256', strtolower(trim((string) $observation->{$identityColumn}))),
            );
    }

    private function expireDueExternalIdentities(?Workspace $workspace): void
    {
        $query = ExternalIdentity::query()
            ->whereNotNull('retain_until')
            ->where('retain_until', '<=', now());
        if ($workspace !== null) {
            $query->where('workspace_id', $workspace->id);
        }

        $query->orderBy('id')->chunkById(100, function ($identities): void {
            foreach ($identities as $identity) {
                DB::transaction(function () use ($identity): void {
                    $locked = ExternalIdentity::query()->whereKey($identity->id)->lockForUpdate()->first();
                    $retainUntil = $locked?->getAttribute('retain_until');
                    if ($locked === null || ! $retainUntil instanceof CarbonInterface || $retainUntil->isFuture()) {
                        return;
                    }
                    $this->refreshExternalIdentity($locked);
                }, 3);
            }
        });
    }

    private function rebuildAccountMemberships(SourceObservation $observation): void
    {
        $membership = AccountSourceMembership::query()
            ->where('prospect_source_id', $observation->prospect_source_id)
            ->where('account_id', $observation->account_id)
            ->where('last_source_observation_id', $observation->id)
            ->first();
        if ($membership === null) {
            return;
        }
        $latest = SourceObservation::query()
            ->where('prospect_source_id', $observation->prospect_source_id)
            ->where('account_id', $observation->account_id)
            ->whereNull('expired_at')
            ->whereIn('disposition', ['new', 'linked', 'duplicate'])
            ->orderByDesc('retrieved_at')
            ->orderByDesc('id')
            ->first();
        if ($latest === null) {
            $membership->forceFill(['state' => MembershipState::Expired, 'expired_at' => now()])->save();

            return;
        }
        $membership->forceFill([
            'last_source_run_id' => $latest->source_run_id,
            'last_source_observation_id' => $latest->id,
            'last_observed_at' => $latest->retrieved_at,
            'state' => MembershipState::Active,
            'expired_at' => null,
        ])->save();
    }

    private function rebuildPersonMemberships(SourceObservation $observation): void
    {
        $membership = PersonSourceMembership::query()
            ->where('prospect_source_id', $observation->prospect_source_id)
            ->where('person_id', $observation->person_id)
            ->where('last_source_observation_id', $observation->id)
            ->first();
        if ($membership === null) {
            return;
        }
        $latest = SourceObservation::query()
            ->where('prospect_source_id', $observation->prospect_source_id)
            ->where('person_id', $observation->person_id)
            ->whereNull('expired_at')
            ->whereIn('disposition', ['new', 'linked', 'duplicate'])
            ->orderByDesc('retrieved_at')
            ->orderByDesc('id')
            ->first();
        if ($latest === null) {
            $membership->forceFill(['state' => MembershipState::Expired, 'expired_at' => now()])->save();

            return;
        }
        $membership->forceFill([
            'last_source_run_id' => $latest->source_run_id,
            'last_source_observation_id' => $latest->id,
            'last_observed_at' => $latest->retrieved_at,
            'state' => MembershipState::Active,
            'expired_at' => null,
        ])->save();
    }

    public function expire(?Workspace $workspace = null, ?ProspectSource $source = null): int
    {
        return $this->handle($workspace, $source);
    }
}
