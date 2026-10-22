<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\Enums\ResearchClaimStatus;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\Account;
use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvidence;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/** Build a current Account view without mutating immutable intelligence rows. */
final class ProjectAccountIntelligence
{
    /** @return array<string,mixed> */
    public function handle(Workspace $workspace, Account|int $account): array
    {
        $accountModel = $account instanceof Account
            ? Account::query()->where('workspace_id', $workspace->id)->whereKey($account->id)->firstOrFail()
            : Account::query()->where('workspace_id', $workspace->id)->whereKey($account)->firstOrFail();
        $claims = ResearchClaim::query()
            ->where('workspace_id', $workspace->id)
            ->where('account_id', $accountModel->id)
            ->with('evidence.sourceSnapshot')
            ->orderBy('created_at')
            ->get();

        $projection = [];
        foreach ($claims->groupBy('predicate') as $predicate => $predicateClaims) {
            /** @var Collection<int,ResearchClaim> $predicateClaims */
            $effective = $this->effectiveClaims($predicateClaims);
            $projection[(string) $predicate] = $this->projectedValues($effective);
        }

        $flat = array_merge(...array_values($projection ?: [[]]));
        $byStatus = [];
        foreach (ResearchClaimStatus::cases() as $status) {
            $byStatus[$status->value] = array_values(array_filter($flat, static fn (array $claim): bool => $claim['status'] === $status->value));
        }

        return [
            'account_id' => (int) $accountModel->id,
            'workspace_id' => (int) $workspace->id,
            'claims' => $flat,
            'by_predicate' => $projection,
            'by_status' => $byStatus,
            'qualification_claim_ids' => array_map(
                static fn (array $claim): string => $claim['claim_id'],
                array_filter($flat, static fn (array $claim): bool => $claim['eligible_for_qualification'] === true),
            ),
        ];
    }

    /** @return array<string,mixed> */
    public function project(Workspace $workspace, Account|int $account): array
    {
        return $this->handle($workspace, $account);
    }

    /**
     * @param  Collection<int,ResearchClaim>  $claims
     * @return list<ResearchClaim>
     */
    private function effectiveClaims(Collection $claims): array
    {
        $superseded = $claims->pluck('supersedes_claim_id')->filter()->map(static fn ($id): string => (string) $id)->all();
        $effective = $claims->reject(static fn (ResearchClaim $claim): bool => in_array((string) $claim->id, $superseded, true));
        $ownerConfirmed = $effective->filter(static fn (ResearchClaim $claim): bool => ModelAttribute::enumValue($claim->status) === ResearchClaimStatus::OwnerConfirmed->value);
        if ($ownerConfirmed->isNotEmpty()) {
            return [$ownerConfirmed->sortByDesc('created_at')->first()];
        }

        return array_values($effective->values()->all());
    }

    /**
     * @param  list<ResearchClaim>  $claims
     * @return list<array<string,mixed>>
     */
    private function projectedValues(array $claims): array
    {
        $values = [];
        foreach ($claims as $claim) {
            $status = $this->projectedStatus($claim);
            $values[] = [
                'claim_id' => (string) $claim->id,
                'predicate' => (string) $claim->predicate,
                'value' => $claim->value_json,
                'value_type' => (string) $claim->value_type,
                'status' => $status->value,
                'confidence' => (float) $claim->confidence,
                'observed_at' => ModelAttribute::instant($claim->observed_at)?->toIso8601String(),
                'fresh_until' => ModelAttribute::instant($claim->fresh_until)?->toIso8601String(),
                'evidence_ids' => $claim->evidence
                    ->filter(fn (ResearchClaimEvidence $edge): bool => $this->edgeUsable($edge))
                    ->pluck('source_snapshot_id')->values()->all(),
                'eligible_for_qualification' => in_array($status, [ResearchClaimStatus::Supported, ResearchClaimStatus::Inferred, ResearchClaimStatus::OwnerConfirmed], true),
                'safe_for_message' => $status === ResearchClaimStatus::OwnerConfirmed && (bool) $claim->safe_for_message,
            ];
        }

        return $values;
    }

    private function projectedStatus(ResearchClaim $claim): ResearchClaimStatus
    {
        if (ModelAttribute::enumValue($claim->status) === ResearchClaimStatus::Rejected->value) {
            return ResearchClaimStatus::Rejected;
        }
        if (ModelAttribute::enumValue($claim->status) === ResearchClaimStatus::OwnerConfirmed->value) {
            return ResearchClaimStatus::OwnerConfirmed;
        }
        $edges = $claim->evidence;
        $usableSupport = $edges->contains(fn (ResearchClaimEvidence $edge): bool => ModelAttribute::enumValue($edge->edge_type) === 'supports' && $this->edgeUsable($edge));
        $usableContradiction = $edges->contains(fn (ResearchClaimEvidence $edge): bool => ModelAttribute::enumValue($edge->edge_type) === 'contradicts' && $this->edgeUsable($edge));
        $hasExpiredEvidence = $edges->isNotEmpty() && ! $usableSupport && ! $usableContradiction;
        if ($usableSupport && $usableContradiction) {
            return ResearchClaimStatus::Conflicting;
        }
        if ($hasExpiredEvidence || ModelAttribute::instant($claim->fresh_until)?->isPast() === true) {
            return ResearchClaimStatus::Stale;
        }
        if (! $usableSupport) {
            return ResearchClaimStatus::Unsupported;
        }
        if (ModelAttribute::enumValue($claim->status) === ResearchClaimStatus::Inferred->value) {
            return ResearchClaimStatus::Inferred;
        }

        return ResearchClaimStatus::Supported;
    }

    private function edgeUsable(ResearchClaimEvidence $edge): bool
    {
        $snapshot = $edge->sourceSnapshot;
        if ($snapshot === null || ! in_array(ModelAttribute::enumValue($snapshot->state), ['available', 'partial'], true)) {
            return false;
        }
        if (ModelAttribute::instant($snapshot->expires_at)?->isPast() === true) {
            return false;
        }
        if ($snapshot->redacted_at !== null) {
            return false;
        }

        return true;
    }
}
