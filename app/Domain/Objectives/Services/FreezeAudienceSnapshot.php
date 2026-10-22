<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\DTOs\SegmentEvaluation;
use App\Domain\Objectives\Support\AudiencePreviewToken;
use App\Models\AudienceSnapshot;
use App\Models\AudienceSnapshotMember;
use App\Models\AudienceSnapshotMemberSource;
use App\Models\Objective;
use App\Models\OutreachPolicy;
use App\Models\SegmentVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FreezeAudienceSnapshot
{
    public function __construct(
        private readonly EvaluateSegment $evaluate,
        private readonly AudiencePreviewToken $tokens,
    ) {}

    public function handle(
        Workspace $workspace,
        SegmentVersion $segmentVersion,
        User $actor,
        string $previewHash,
        string $previewToken,
        ?string $objectiveId = null,
    ): AudienceSnapshot {
        if ($objectiveId !== null) {
            Objective::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($objectiveId)
                ->whereIn('status', ['draft', 'ready'])
                ->firstOrFail();
        }
        $evaluation = $this->evaluate->handle($workspace, $segmentVersion);
        $this->tokens->assertValid($previewToken, (int) $workspace->id, (string) $segmentVersion->id, $previewHash);
        if (! hash_equals($evaluation->evaluationHash, $previewHash)) {
            throw ValidationException::withMessages(['preview_hash' => 'audience_preview_changed']);
        }
        $this->assertCaps($workspace, $evaluation);

        return DB::transaction(function () use ($workspace, $segmentVersion, $actor, $evaluation, $objectiveId): AudienceSnapshot {
            SegmentVersion::query()
                ->whereKey($segmentVersion->id)
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->firstOrFail();
            $existing = AudienceSnapshot::query()
                ->where('workspace_id', $workspace->id)
                ->where('segment_version_id', $segmentVersion->id)
                ->where('evaluation_hash', $evaluation->evaluationHash)
                ->where('objective_id', $objectiveId)
                ->first();
            if ($existing !== null) {
                return $existing->load('members.sources');
            }
            $snapshot = AudienceSnapshot::query()->create([
                'workspace_id' => $workspace->id,
                'segment_version_id' => $segmentVersion->id,
                'objective_id' => $objectiveId,
                'purpose' => 'objective_execution',
                'status' => 'frozen',
                'evaluator_version' => 'segment_evaluator_v1',
                'criteria_hash' => $evaluation->criteriaHash,
                'evaluation_hash' => $evaluation->evaluationHash,
                'included_people_count' => $evaluation->counts['included_people'],
                'excluded_people_count' => $evaluation->counts['excluded_people'],
                'uncertain_people_count' => $evaluation->counts['uncertain_people'],
                'suppressed_people_count' => $evaluation->counts['suppressed_people'],
                'included_accounts_count' => $evaluation->counts['included_accounts'],
                'excluded_accounts_count' => $evaluation->counts['excluded_accounts'],
                'uncertain_accounts_count' => $evaluation->counts['uncertain_accounts'],
                'suppressed_accounts_count' => $evaluation->counts['suppressed_accounts'],
                'source_rights_snapshot' => $evaluation->sourceRights,
                'frozen_by_user_id' => $actor->id,
                'frozen_at' => now(),
            ]);
            foreach ($evaluation->members as $row) {
                $member = AudienceSnapshotMember::query()->create([
                    'workspace_id' => $workspace->id,
                    'audience_snapshot_id' => $snapshot->id,
                    'account_id' => $row['account_id'],
                    'person_id' => $row['person_id'],
                    'result' => $row['result'],
                    'qualification_decision_id' => $row['qualification_decision_id'],
                    'reason_to_contact_version_id' => $row['reason_to_contact_version_id'],
                    'criterion_results' => $row['criterion_results'],
                    'inclusion_reasons' => $row['inclusion_reasons'],
                    'exclusion_reasons' => $row['exclusion_reasons'],
                    'missing_fields' => $row['missing_fields'],
                    'suppression_snapshot' => $row['suppression_snapshot'],
                    'evidence_manifest' => $row['evidence_manifest'],
                    'qualification_content_hash' => $row['qualification_content_hash'],
                    'reason_content_hash' => $row['reason_content_hash'],
                    'source_lineage_hash' => $row['source_lineage_hash'],
                    'member_hash' => $row['member_hash'],
                    'evidence_fresh_until' => $row['evidence_fresh_until'],
                    'evaluated_at' => $row['evaluated_at'],
                ]);
                foreach ($row['source_memberships'] as $source) {
                    AudienceSnapshotMemberSource::query()->create([
                        'workspace_id' => $workspace->id,
                        'audience_snapshot_id' => $snapshot->id,
                        'audience_snapshot_member_id' => $member->id,
                        'prospect_source_id' => $source['prospect_source_id'],
                        'prospect_source_version_id' => $source['prospect_source_version_id'],
                        'source_observation_id' => $source['source_observation_id'],
                        'person_source_membership_id' => $source['person_source_membership_id'],
                        'policy_hash' => $source['policy_hash'],
                        'rights_result' => $source['rights_result'],
                        'observed_at' => $source['observed_at'],
                        'expires_at' => $source['expires_at'],
                    ]);
                }
            }

            return $snapshot->load('members.sources');
        }, 3);
    }

    private function assertCaps(Workspace $workspace, SegmentEvaluation $evaluation): void
    {
        $policy = OutreachPolicy::query()->with('currentVersion')->where('workspace_id', $workspace->id)->first();
        if ($policy?->currentVersion === null) {
            throw ValidationException::withMessages(['policy' => 'outreach_policy_missing']);
        }
        $maximumPeople = min((int) config('objectives.safety.maximum_people'), (int) $policy->currentVersion->maximum_people);
        $maximumAccounts = min((int) config('objectives.safety.maximum_accounts'), (int) $policy->currentVersion->maximum_accounts);
        if ($evaluation->counts['included_people'] > $maximumPeople || $evaluation->counts['included_accounts'] > $maximumAccounts) {
            throw ValidationException::withMessages(['criteria' => 'audience_cap_exceeded']);
        }
    }
}
