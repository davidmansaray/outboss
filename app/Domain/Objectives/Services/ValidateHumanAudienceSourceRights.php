<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Prospecting\Support\SourcePolicyEnforcer;
use App\Models\AudienceSnapshotMember;
use App\Models\AudienceSnapshotMemberSource;
use App\Models\PersonSourceMembership;
use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Revalidate frozen human provenance without substituting a newly added source. */
final class ValidateHumanAudienceSourceRights
{
    /** @return list<array<string, mixed>> Current eligible frozen-source dependency facts. */
    public function handle(AudienceSnapshotMember $member): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Source-rights validation requires the enclosing review transaction.');
        }
        $workspaceId = (int) $member->getAttribute('workspace_id');
        $links = AudienceSnapshotMemberSource::query()->where('workspace_id', $workspaceId)
            ->where('audience_snapshot_id', $member->audience_snapshot_id)
            ->where('audience_snapshot_member_id', $member->id)->where('rights_result', 'allowed')
            ->orderBy('id')->lockForUpdate()->get();
        $facts = [];
        foreach ($links as $link) {
            $membership = PersonSourceMembership::query()->where('workspace_id', $workspaceId)
                ->where('person_id', $member->person_id)->where('prospect_source_id', $link->prospect_source_id)
                ->whereKey($link->person_source_membership_id)->where('state', 'active')->lockForUpdate()->first();
            $source = ProspectSource::query()->where('workspace_id', $workspaceId)
                ->whereKey($link->prospect_source_id)->where('status', 'active')
                ->where('current_version_id', $link->prospect_source_version_id)->lockForUpdate()->first();
            $version = ProspectSourceVersion::query()->where('workspace_id', $workspaceId)
                ->where('prospect_source_id', $link->prospect_source_id)
                ->whereKey($link->prospect_source_version_id)->lockForUpdate()->first();
            if ($membership === null || $source === null || $version === null
                || ($membership->expired_at !== null && ! $membership->expired_at->isFuture())
                || ($link->expires_at !== null && ! Carbon::parse($link->expires_at)->isFuture())) {
                continue;
            }
            $rights = app(SourcePolicyEnforcer::class)->contactEligibility($version, 'prospecting');
            if (! $rights['allowed']
                || ! hash_equals((string) $link->policy_hash, $rights['policy_hash'])) {
                continue;
            }
            $facts[] = [
                'source_id' => $source->id, 'source_version_id' => $version->id,
                'membership_id' => $membership->id, 'policy_hash' => $rights['policy_hash'],
                'membership_expires_at' => $membership->expired_at?->toAtomString(),
                'snapshot_expires_at' => $link->expires_at === null ? null : Carbon::parse($link->expires_at)->toAtomString(),
                'policy' => $version->policy_snapshot,
            ];
        }
        if ($facts === []) {
            throw ValidationException::withMessages([
                'audience' => 'A prospect’s reviewed source no longer permits outreach. Check source rights and prepare a new review; no new source was substituted.',
            ]);
        }

        return $facts;
    }
}
