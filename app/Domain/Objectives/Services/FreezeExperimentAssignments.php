<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\AudienceMemberResult;
use App\Domain\Objectives\Enums\GtmExperimentStatus;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\DeterministicAccountAssignment;
use App\Models\AudienceSnapshotMember;
use App\Models\GtmExperiment;
use App\Models\GtmExperimentAssignment;
use App\Models\GtmExperimentAssignmentMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class FreezeExperimentAssignments
{
    public function handle(GtmExperiment $experiment, User $actor, int $expectedLockVersion): GtmExperiment
    {
        return DB::transaction(function () use ($experiment, $actor, $expectedLockVersion): GtmExperiment {
            $locked = GtmExperiment::query()->where('workspace_id', $experiment->workspace_id)
                ->whereKey($experiment->id)->lockForUpdate()->firstOrFail();
            $workspace = Workspace::query()->whereKey($locked->workspace_id)->firstOrFail();
            if (! $workspace->isOwner($actor)) {
                throw new AuthorizationException('Only the workspace owner can freeze experiment assignments.');
            }
            if ($locked->status === GtmExperimentStatus::Assigned) {
                return $locked->fresh(['arms', 'assignments.members']);
            }
            if ($locked->status !== GtmExperimentStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Only a draft experiment can freeze assignments.']);
            }
            if ((int) $locked->lock_version !== $expectedLockVersion) {
                throw new RuntimeException('stale_version');
            }
            $arms = $locked->arms()->orderByRaw("case when `key` = 'control' then 0 else 1 end")->get();
            if (! in_array($arms->count(), [1, 2], true) || (int) $arms->sum('allocation_basis_points') !== 10000) {
                throw ValidationException::withMessages(['arms' => 'Experiment allocations must contain one or two arms totalling 10,000 basis points.']);
            }
            $members = AudienceSnapshotMember::query()
                ->where('workspace_id', $locked->workspace_id)
                ->where('audience_snapshot_id', $locked->audience_snapshot_id)
                ->where('result', AudienceMemberResult::Included->value)
                ->orderBy('account_id')->orderBy('person_id')->get();
            if ($members->isEmpty() || $members->contains(fn (AudienceSnapshotMember $member): bool => $member->account_id === null || $member->person_id === null)) {
                throw ValidationException::withMessages(['audience' => 'Every included member must retain an Account and Person before assignment.']);
            }

            $seed = random_bytes(32);
            $seedHash = hash('sha256', $seed);
            $assignedMembers = 0;
            foreach ($members->groupBy('account_id')->sortKeys() as $accountId => $accountMembers) {
                $bucket = DeterministicAccountAssignment::bucket($seed, (int) $accountId);
                $arm = DeterministicAccountAssignment::armForBucket($arms, $bucket);
                $eligibilityHash = CanonicalObjectivePayload::hash([
                    'audience_snapshot_id' => (string) $locked->audience_snapshot_id,
                    'account_id' => (int) $accountId,
                    'member_hashes' => $accountMembers->pluck('member_hash')->sort()->values()->all(),
                ]);
                $assignment = GtmExperimentAssignment::query()->create([
                    'workspace_id' => $locked->workspace_id,
                    'gtm_experiment_id' => $locked->id,
                    'gtm_experiment_arm_id' => $arm->id,
                    'audience_snapshot_id' => $locked->audience_snapshot_id,
                    'account_id' => (int) $accountId,
                    'assignment_unit_key_hash' => hash('sha256', 'account:'.$accountId),
                    'bucket' => $bucket,
                    'allocation_version' => $locked->allocation_version,
                    'algorithm_version' => DeterministicAccountAssignment::ALGORITHM_VERSION,
                    'eligibility_snapshot_hash' => $eligibilityHash,
                    'assigned_at' => now(),
                ]);
                foreach ($accountMembers as $member) {
                    GtmExperimentAssignmentMember::query()->create([
                        'workspace_id' => $locked->workspace_id,
                        'gtm_experiment_assignment_id' => $assignment->id,
                        'audience_snapshot_member_id' => $member->id,
                        'person_id' => $member->person_id,
                        'member_hash' => $member->member_hash,
                    ]);
                    $assignedMembers++;
                }
            }
            if ($assignedMembers !== $members->count()
                || GtmExperimentAssignmentMember::query()->where('workspace_id', $locked->workspace_id)
                    ->whereIn('gtm_experiment_assignment_id', $locked->assignments()->pluck('id'))->count() !== $members->count()) {
                throw new RuntimeException('assignment_coverage_mismatch');
            }

            $updated = GtmExperiment::query()->whereKey($locked->id)
                ->where('status', GtmExperimentStatus::Draft->value)
                ->where('lock_version', $expectedLockVersion)
                ->update([
                    'status' => GtmExperimentStatus::Assigned->value,
                    'assignment_seed' => encrypt($seed),
                    'assignment_seed_hash' => $seedHash,
                    'assigned_at' => now(),
                    'updated_by_user_id' => $actor->id,
                    'lock_version' => $expectedLockVersion + 1,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('stale_version');
            }

            return $locked->fresh(['arms', 'assignments.members']);
        }, 3);
    }
}
