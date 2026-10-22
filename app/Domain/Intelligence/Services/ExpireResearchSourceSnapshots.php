<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\Enums\QualificationReviewAction;
use App\Domain\Intelligence\Enums\QualificationReviewState;
use App\Domain\Intelligence\Enums\ResearchClaimEventAction;
use App\Domain\Intelligence\Enums\ResearchClaimStatus;
use App\Domain\Intelligence\Enums\ResearchSourceSnapshotState;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\QualificationDecision;
use App\Models\QualificationDecisionClaim;
use App\Models\QualificationReviewEvent;
use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvent;
use App\Models\ResearchClaimEvidence;
use App\Models\ResearchSourceSnapshot;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Redact expired S2 source snapshots without deleting their lineage.
 *
 * Snapshot rows, claims, evidence edges, decisions and audit events are
 * append-only records. The snapshot itself is transitioned through a direct
 * database update because its Eloquent model correctly rejects all updates.
 * Claim and qualification current state is projected from the retained
 * lineage and receives a safe audit event for each redaction.
 */
final class ExpireResearchSourceSnapshots
{
    private const SAFE_FAILURE_CODE = 'retention_expired';

    private const CLAIM_REDACTION_REASON = 'Source snapshot expired; restricted content was redacted.';

    private const QUALIFICATION_REDACTION_REASON = 'Source snapshot expired; dependent qualification requires research.';

    /**
     * Process at most one bounded batch. A nullable workspace is supported for
     * operator catch-up, while scheduled jobs always pass an explicit tenant.
     */
    public function handle(?Workspace $workspace = null, ?int $limit = null): int
    {
        $limit = max(1, min(1000, $limit ?? (int) config('intelligence.research.retention_batch_size', 100)));
        $now = now();

        $query = ResearchSourceSnapshot::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->where(function (Builder $query): void {
                // A partially-completed retry may have changed metadata but
                // not the body. Pick it up again, while a fully redacted row is
                // an idempotent no-op and stays out of the batch.
                $query->whereNull('redacted_at')
                    ->orWhereNotNull('content')
                    ->orWhere('state', '!=', ResearchSourceSnapshotState::Redacted->value);
            });
        if ($workspace !== null) {
            $query->where('workspace_id', $workspace->id);
        }

        $snapshotIds = $query->orderBy('id')->limit($limit)->pluck('id');
        $redacted = 0;
        foreach ($snapshotIds as $snapshotId) {
            if ($this->redactSnapshot((string) $snapshotId, $workspace?->id, $now)) {
                $redacted++;
            }
        }

        return $redacted;
    }

    public function expire(?Workspace $workspace = null, ?int $limit = null): int
    {
        return $this->handle($workspace, $limit);
    }

    private function redactSnapshot(string $snapshotId, ?int $workspaceId, CarbonInterface $now): bool
    {
        return DB::transaction(function () use ($snapshotId, $workspaceId, $now): bool {
            $query = ResearchSourceSnapshot::query()
                ->whereKey($snapshotId)
                ->lockForUpdate();
            if ($workspaceId !== null) {
                $query->where('workspace_id', $workspaceId);
            }

            /** @var ResearchSourceSnapshot|null $snapshot */
            $snapshot = $query->first();
            if ($snapshot === null) {
                return false;
            }

            $expiresAt = ModelAttribute::instant($snapshot->expires_at);
            if ($expiresAt === null || $expiresAt->isFuture()) {
                return false;
            }

            $state = ModelAttribute::enumValue($snapshot->state);
            if ($state === ResearchSourceSnapshotState::Redacted->value
                && $snapshot->redacted_at !== null
                && $snapshot->content === null
                && $snapshot->payload_reference === null) {
                return false;
            }

            $redactedAt = ModelAttribute::instant($snapshot->redacted_at) ?? $now;
            DB::table('research_source_snapshots')
                ->where('id', $snapshot->id)
                ->where('workspace_id', $snapshot->workspace_id)
                ->update([
                    'content' => null,
                    'payload_reference' => null,
                    'state' => ResearchSourceSnapshotState::Redacted->value,
                    'safe_failure_code' => self::SAFE_FAILURE_CODE,
                    'redacted_at' => $redactedAt,
                    'updated_at' => $now,
                ]);

            $invalidatedClaimIds = $this->auditClaimRedactions($snapshot);
            $this->invalidateQualifications($snapshot, $invalidatedClaimIds);

            return true;
        }, 3);
    }

    /** @return list<string> */
    private function auditClaimRedactions(ResearchSourceSnapshot $snapshot): array
    {
        $claimIds = ResearchClaimEvidence::query()
            ->where('workspace_id', $snapshot->workspace_id)
            ->where('source_snapshot_id', $snapshot->id)
            ->distinct()
            ->pluck('claim_id');
        if ($claimIds->isEmpty()) {
            return [];
        }

        $claims = ResearchClaim::query()
            ->where('workspace_id', $snapshot->workspace_id)
            ->where('account_id', $snapshot->account_id)
            ->whereIn('id', $claimIds->all())
            ->get();
        $invalidatedClaimIds = [];
        foreach ($claims as $claim) {
            $projectedStatus = $this->projectedClaimStatus($claim);
            ResearchClaimEvent::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $snapshot->workspace_id,
                'account_id' => $claim->account_id,
                'claim_id' => $claim->id,
                'action' => ResearchClaimEventAction::PolicyRedact,
                'actor_snapshot' => ['actor_type' => 'system', 'display_name' => 'Retention worker'],
                'actor_user_id' => null,
                'reason' => self::CLAIM_REDACTION_REASON,
                'successor_claim_id' => null,
                'prior_status' => ModelAttribute::enumValue($claim->status) ?? ResearchClaimStatus::Unsupported->value,
                'projected_status' => $projectedStatus,
                'created_at' => now(),
            ]);
            if (! in_array($projectedStatus, [
                ResearchClaimStatus::Supported->value,
                ResearchClaimStatus::Inferred->value,
                ResearchClaimStatus::OwnerConfirmed->value,
            ], true)) {
                $invalidatedClaimIds[] = (string) $claim->id;
            }
        }

        return $invalidatedClaimIds;
    }

    private function projectedClaimStatus(ResearchClaim $claim): string
    {
        $status = ModelAttribute::enumValue($claim->status);
        if ($status === ResearchClaimStatus::OwnerConfirmed->value) {
            return ResearchClaimStatus::OwnerConfirmed->value;
        }
        if ($status === ResearchClaimStatus::Rejected->value) {
            return ResearchClaimStatus::Rejected->value;
        }

        // The current projection will re-check every edge after the snapshot
        // update. If another unexpired supporting edge remains, preserve that
        // status in the audit record; otherwise the claim becomes stale.
        $edges = $claim->evidence()->with('sourceSnapshot')->get();
        $usableSupport = $edges->contains(function (ResearchClaimEvidence $edge): bool {
            $source = $edge->sourceSnapshot;
            if ($source === null || ! in_array(ModelAttribute::enumValue($source->state), [
                ResearchSourceSnapshotState::Available->value,
                ResearchSourceSnapshotState::Partial->value,
            ], true)) {
                return false;
            }
            if (ModelAttribute::instant($source->expires_at)?->isPast() === true || $source->redacted_at !== null) {
                return false;
            }

            return ModelAttribute::enumValue($edge->edge_type) === 'supports';
        });
        $usableContradiction = $edges->contains(function (ResearchClaimEvidence $edge): bool {
            $source = $edge->sourceSnapshot;
            if ($source === null || ! in_array(ModelAttribute::enumValue($source->state), [
                ResearchSourceSnapshotState::Available->value,
                ResearchSourceSnapshotState::Partial->value,
            ], true)) {
                return false;
            }
            if (ModelAttribute::instant($source->expires_at)?->isPast() === true || $source->redacted_at !== null) {
                return false;
            }

            return ModelAttribute::enumValue($edge->edge_type) === 'contradicts';
        });
        if ($usableSupport && $usableContradiction) {
            return ResearchClaimStatus::Conflicting->value;
        }
        if ($edges->isNotEmpty() && ! $usableSupport && ! $usableContradiction) {
            return ResearchClaimStatus::Stale->value;
        }
        if (ModelAttribute::instant($claim->fresh_until)?->isPast() === true) {
            return ResearchClaimStatus::Stale->value;
        }
        if ($usableSupport) {
            return $status === ResearchClaimStatus::Inferred->value
                ? ResearchClaimStatus::Inferred->value
                : ResearchClaimStatus::Supported->value;
        }

        return ResearchClaimStatus::Unsupported->value;
    }

    /** @param list<string> $claimIds */
    private function invalidateQualifications(ResearchSourceSnapshot $snapshot, array $claimIds): void
    {
        if ($claimIds === []) {
            return;
        }

        $decisionIds = QualificationDecisionClaim::query()
            ->where('workspace_id', $snapshot->workspace_id)
            ->whereIn('claim_id', $claimIds)
            ->distinct()
            ->pluck('decision_id');
        if ($decisionIds->isEmpty()) {
            return;
        }

        $decisions = QualificationDecision::query()
            ->where('workspace_id', $snapshot->workspace_id)
            ->where('account_id', $snapshot->account_id)
            ->whereIn('id', $decisionIds->all())
            ->lockForUpdate()
            ->get();
        foreach ($decisions as $decision) {
            $priorState = ModelAttribute::enumValue($decision->review_state) ?? QualificationReviewState::Pending->value;
            if ($priorState === QualificationReviewState::ResearchRequested->value) {
                continue;
            }

            // review_state is the deliberate mutable current projection; the
            // generated decision payload and all claim links remain immutable.
            $decision->forceFill([
                'review_state' => QualificationReviewState::ResearchRequested,
                'reviewed_at' => $now = now(),
                'updated_at' => $now,
            ])->save();

            QualificationReviewEvent::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $snapshot->workspace_id,
                'account_id' => $decision->account_id,
                'decision_id' => $decision->id,
                'action' => QualificationReviewAction::RequestResearch,
                'actor_snapshot' => ['actor_type' => 'system', 'display_name' => 'Retention worker'],
                'actor_user_id' => null,
                'reason' => self::QUALIFICATION_REDACTION_REASON,
                'prior_review_state' => $priorState,
                'projected_review_state' => QualificationReviewState::ResearchRequested->value,
                'successor_decision_id' => null,
                'successor_run_id' => null,
                'created_at' => now(),
            ]);
        }
    }
}
