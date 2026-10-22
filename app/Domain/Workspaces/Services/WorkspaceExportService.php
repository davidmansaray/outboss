<?php

namespace App\Domain\Workspaces\Services;

use App\Domain\Campaigns\Services\CampaignOutcomeCounts;
use App\Models\AccountResearchRun;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ExternalOperation;
use App\Models\IntegrationConnection;
use App\Models\ProspectSource;
use App\Models\SuppressionEntry;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkspaceExportService
{
    public function __construct(private CampaignOutcomeCounts $outcomes) {}

    /**
     * @return list<string>
     */
    public function enrollmentHeaders(): array
    {
        return [
            'campaign',
            'campaign_status',
            'email',
            'full_name',
            'first_name',
            'company',
            'email_domain',
            'person_id',
            'account_id',
            'mailbox',
            'sequence_version',
            'status',
            'stop_reason',
            'enrolled_at',
            'first_sent_at',
            'last_sent_at',
            'replied_at',
            'stopped_at',
            'paused_at',
            'removed_at',
        ];
    }

    /**
     * @return iterable<int, list<int|string|null>>
     */
    public function enrollmentRows(int $workspaceId): iterable
    {
        $recipients = CampaignRecipient::query()
            ->where('workspace_id', $workspaceId)
            ->with(['campaign', 'mailbox', 'sequenceVersion'])
            ->lazyById();

        foreach ($recipients as $recipient) {
            yield [
                $this->relatedScalar($recipient->campaign, 'name'),
                $this->relatedScalar($recipient->campaign, 'status'),
                $this->scalar($recipient->getAttribute('email_address')),
                $this->scalar($recipient->getAttribute('full_name')),
                $this->scalar($recipient->getAttribute('first_name')),
                $this->scalar($recipient->getAttribute('company')),
                $this->scalar($recipient->getAttribute('email_domain')),
                $this->scalar($recipient->getAttribute('person_id')),
                $this->scalar($recipient->getAttribute('account_id')),
                $this->relatedScalar($recipient->mailbox, 'email_address'),
                $this->relatedScalar($recipient->sequenceVersion, 'version'),
                $this->scalar($recipient->getAttribute('status')),
                $this->scalar($recipient->getAttribute('stop_reason')),
                $this->iso8601($recipient->getAttribute('created_at')),
                $this->iso8601($recipient->getAttribute('first_sent_at')),
                $this->iso8601($recipient->getAttribute('last_sent_at')),
                $this->iso8601($recipient->getAttribute('replied_at')),
                $this->iso8601($recipient->getAttribute('stopped_at')),
                $this->iso8601($recipient->getAttribute('paused_at')),
                $this->iso8601($recipient->getAttribute('removed_at')),
            ];
        }
    }

    /**
     * @return list<string>
     */
    public function suppressionHeaders(): array
    {
        return ['email', 'reason', 'created_at'];
    }

    /**
     * @return iterable<int, list<int|string|null>>
     */
    public function suppressionRows(int $workspaceId): iterable
    {
        $entries = SuppressionEntry::query()
            ->where('workspace_id', $workspaceId)
            ->lazyById();

        foreach ($entries as $entry) {
            yield [
                $this->scalar($entry->getAttribute('email_address')),
                $this->scalar($entry->getAttribute('reason')),
                $this->iso8601($entry->getAttribute('created_at')),
            ];
        }
    }

    /**
     * @return list<string>
     */
    public function campaignOutcomeHeaders(): array
    {
        return [
            'campaign',
            'status',
            'created_at',
            'launched_at',
            'completed_at',
            'queued',
            'sent',
            'replied',
            'interested',
            'bounced',
            'unsubscribed',
            'needs_review',
        ];
    }

    /**
     * @return iterable<int, list<int|string|null>>
     */
    public function campaignOutcomeRows(int $workspaceId): iterable
    {
        $campaigns = Campaign::query()
            ->where('workspace_id', $workspaceId)
            ->lazyById();

        foreach ($campaigns as $campaign) {
            $counts = $this->outcomes->counts($campaign);

            yield [
                $this->scalar($campaign->getAttribute('name')),
                $this->scalar($campaign->getAttribute('status')),
                $this->iso8601($campaign->getAttribute('created_at')),
                $this->iso8601($campaign->getAttribute('launched_at')),
                $this->iso8601($campaign->getAttribute('completed_at')),
                $counts['queued'],
                $counts['sent'],
                $counts['replied'],
                $counts['interested'],
                $counts['bounced'],
                $counts['unsubscribed'],
                $counts['needs_review'],
            ];
        }
    }

    /**
     * Operational source summaries are safe to include in a workspace export:
     * they contain aggregate IDs, policy hashes and counts, never observed
     * values, source URLs, uploaded rows or provider payloads.
     *
     * @return list<string>
     */
    public function sourceHeaders(): array
    {
        return [
            'source_id',
            'source_name',
            'kind',
            'status',
            'current_version_id',
            'policy_hash',
            'version_count',
            'run_count',
            'observation_count',
            'account_membership_count',
            'person_membership_count',
            'latest_observed_at',
        ];
    }

    /**
     * @return iterable<int, list<int|string|null>>
     */
    public function sourceRows(int $workspaceId): iterable
    {
        $sources = ProspectSource::query()
            ->where('workspace_id', $workspaceId)
            ->withCount([
                'versions',
                'runs',
                'observations',
                'accountMemberships',
                'personMemberships',
            ])
            ->with('currentVersion:id,policy_hash')
            ->orderBy('id')
            ->lazyById();

        foreach ($sources as $source) {
            $latestObservedAt = $source->observations()
                ->max('retrieved_at');

            yield [
                (string) $source->id,
                $this->scalar($source->display_name),
                $this->scalar($source->kind),
                $this->scalar($source->status),
                $source->current_version_id !== null ? (string) $source->current_version_id : null,
                $source->currentVersion?->policy_hash,
                (int) ($source->versions_count ?? 0),
                (int) ($source->runs_count ?? 0),
                (int) ($source->observations_count ?? 0),
                (int) ($source->account_memberships_count ?? 0),
                (int) ($source->person_memberships_count ?? 0),
                $this->iso8601($latestObservedAt),
            ];
        }
    }

    /**
     * Integration exports expose operational state only.  In particular,
     * encrypted credential versions, idempotency keys, request fingerprints
     * and provider request bodies are intentionally absent.
     *
     * @return list<string>
     */
    public function integrationHeaders(): array
    {
        return [
            'provider',
            'connection_status',
            'registration_count',
            'enabled_registration_count',
            'operation_count',
            'succeeded_operation_count',
            'ambiguous_operation_count',
            'reserved_cost_micros',
            'actual_cost_micros',
            'last_tested_at',
            'last_healthy_at',
        ];
    }

    /**
     * @return iterable<int, list<int|string|null>>
     */
    public function integrationRows(int $workspaceId): iterable
    {
        $connections = IntegrationConnection::query()
            ->where('workspace_id', $workspaceId)
            ->withCount([
                'registrations',
                'operations',
            ])
            ->with('registrations:id,connection_id,enabled')
            ->orderBy('id')
            ->lazyById();

        foreach ($connections as $connection) {
            $operations = ExternalOperation::query()
                ->where('workspace_id', $workspaceId)
                ->where('connection_id', $connection->id);
            $statusCounts = (clone $operations)
                ->select('status', DB::raw('count(*) as aggregate'))
                ->groupBy('status')
                ->pluck('aggregate', 'status');
            $succeeded = (int) $statusCounts->only(['succeeded', 'reconciled'])->sum();
            $ambiguous = (int) $statusCounts->only([
                'ambiguous',
                'uncertain',
                'reconciling',
                'reconciliation_pending',
            ])->sum();
            $reserved = (int) ((clone $operations)->sum('reserved_cost_micros'));
            $actual = (int) ((clone $operations)->sum('actual_cost_micros'));

            yield [
                $this->scalar($connection->provider),
                $this->scalar($connection->status),
                (int) ($connection->registrations_count ?? 0),
                $connection->registrations->where('enabled', true)->count(),
                (int) ($connection->operations_count ?? 0),
                $succeeded,
                $ambiguous,
                $reserved,
                $actual,
                $this->iso8601($connection->last_tested_at),
                $this->iso8601($connection->last_healthy_at),
            ];
        }
    }

    /**
     * Return the bounded S2 manifest sections for callers that expose a
     * complete workspace export.  Rows stay lazy; a controller can stream a
     * selected section or cap it through records() without materialising the
     * workspace graph.
     *
     * @return array<string, array{headers: list<string>, rows: iterable<int, list<int|string|null>>}>
     */
    public function s2Sections(int $workspaceId): array
    {
        return [
            'research_runs' => ['headers' => $this->researchHeaders(), 'rows' => $this->researchRows($workspaceId)],
            'research_inputs' => ['headers' => $this->researchInputHeaders(), 'rows' => $this->researchInputRows($workspaceId)],
            'research_stages' => ['headers' => $this->researchStageHeaders(), 'rows' => $this->researchStageRows($workspaceId)],
            'research_attempts' => ['headers' => $this->researchAttemptHeaders(), 'rows' => $this->researchAttemptRows($workspaceId)],
            'research_dispatches' => ['headers' => $this->researchDispatchHeaders(), 'rows' => $this->researchDispatchRows($workspaceId)],
            'research_snapshots' => ['headers' => $this->researchSnapshotHeaders(), 'rows' => $this->researchSnapshotRows($workspaceId)],
            'research_claims' => ['headers' => $this->researchClaimHeaders(), 'rows' => $this->researchClaimRows($workspaceId)],
            'research_evidence' => ['headers' => $this->researchEvidenceHeaders(), 'rows' => $this->researchEvidenceRows($workspaceId)],
            'research_claim_events' => ['headers' => $this->researchClaimEventHeaders(), 'rows' => $this->researchClaimEventRows($workspaceId)],
            'qualification_rubrics' => ['headers' => $this->qualificationRubricHeaders(), 'rows' => $this->qualificationRubricRows($workspaceId)],
            'qualification_rubric_versions' => ['headers' => $this->qualificationRubricVersionHeaders(), 'rows' => $this->qualificationRubricVersionRows($workspaceId)],
            'qualification_decisions' => ['headers' => $this->qualificationDecisionHeaders(), 'rows' => $this->qualificationDecisionRows($workspaceId)],
            'qualification_decision_claims' => ['headers' => $this->qualificationDecisionClaimHeaders(), 'rows' => $this->qualificationDecisionClaimRows($workspaceId)],
            'qualification_reviews' => ['headers' => $this->qualificationReviewHeaders(), 'rows' => $this->qualificationReviewRows($workspaceId)],
            'reasons_to_contact' => ['headers' => $this->reasonToContactHeaders(), 'rows' => $this->reasonToContactRows($workspaceId)],
            'reason_claims' => ['headers' => $this->reasonToContactClaimHeaders(), 'rows' => $this->reasonToContactClaimRows($workspaceId)],
            'conversion_connections' => ['headers' => $this->conversionConnectionHeaders(), 'rows' => $this->conversionConnectionRows($workspaceId)],
            'conversion_connection_versions' => ['headers' => $this->conversionConnectionVersionHeaders(), 'rows' => $this->conversionConnectionVersionRows($workspaceId)],
            'conversion_definitions' => ['headers' => $this->conversionDefinitionHeaders(), 'rows' => $this->conversionDefinitionRows($workspaceId)],
            'conversion_definition_versions' => ['headers' => $this->conversionDefinitionVersionHeaders(), 'rows' => $this->conversionDefinitionVersionRows($workspaceId)],
            'conversion_receipts' => ['headers' => $this->conversionEventReceiptHeaders(), 'rows' => $this->conversionEventReceiptRows($workspaceId)],
            'conversion_events' => ['headers' => $this->conversionEventHeaders(), 'rows' => $this->conversionEventRows($workspaceId)],
            'conversion_matches' => ['headers' => $this->conversionEventMatchHeaders(), 'rows' => $this->conversionEventMatchRows($workspaceId)],
            'conversion_reviews' => ['headers' => $this->conversionEventReviewHeaders(), 'rows' => $this->conversionEventReviewRows($workspaceId)],
            'offers' => ['headers' => $this->offerHeaders(), 'rows' => $this->offerRows($workspaceId)],
            'offer_versions' => ['headers' => $this->offerVersionHeaders(), 'rows' => $this->offerVersionRows($workspaceId)],
            'offer_readiness' => ['headers' => $this->offerReadinessHeaders(), 'rows' => $this->offerReadinessRows($workspaceId)],
            'offer_reviews' => ['headers' => $this->offerVersionReviewHeaders(), 'rows' => $this->offerVersionReviewRows($workspaceId)],
            'offer_assets' => ['headers' => $this->offerVersionAssetHeaders(), 'rows' => $this->offerVersionAssetRows($workspaceId)],
            'assets' => ['headers' => $this->assetHeaders(), 'rows' => $this->assetRows($workspaceId)],
            'asset_versions' => ['headers' => $this->assetVersionHeaders(), 'rows' => $this->assetVersionRows($workspaceId)],
            'fulfilment' => ['headers' => $this->fulfilmentHeaders(), 'rows' => $this->fulfilmentRows($workspaceId)],
            'fulfilment_capabilities' => ['headers' => $this->fulfilmentCapabilityHeaders(), 'rows' => $this->fulfilmentCapabilityRows($workspaceId)],
            'fulfilment_reservations' => ['headers' => $this->fulfilmentReservationHeaders(), 'rows' => $this->fulfilmentReservationRows($workspaceId)],
            'fulfilment_capacity_windows' => ['headers' => $this->fulfilmentCapacityWindowHeaders(), 'rows' => $this->fulfilmentCapacityWindowRows($workspaceId)],
        ];
    }

    /**
     * Account research exports contain orchestration and cost metadata only.
     * Account snapshots, idempotency keys, provider operation identifiers and
     * checkpoints are deliberately not exportable.
     *
     * @return list<string>
     */
    public function researchHeaders(): array
    {
        return [
            'run_id',
            'account_id',
            'state',
            'current_stage',
            'maximum_cost_micros',
            'estimated_cost_micros',
            'reserved_cost_micros',
            'actual_cost_micros',
            'currency',
            'orchestration_version',
            'input_hash',
            'policy_hash',
            'capability_hash',
            'freshness_policy_hash',
            'queued_at',
            'started_at',
            'finished_at',
            'cancelled_at',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return iterable<int, list<int|string|null>>
     */
    public function researchRows(int $workspaceId): iterable
    {
        if (! $this->hasTable('account_research_runs')) {
            return;
        }

        $runs = AccountResearchRun::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->lazyById(500, 'id');

        foreach ($runs as $run) {
            $costs = $run->costProjection();

            yield [
                $this->rowString($run, 'id'),
                $this->rowInt($run, 'account_id'),
                $this->rowString($run, 'state'),
                $this->rowString($run, 'current_stage'),
                $this->rowInt($run, 'maximum_cost_micros'),
                $this->rowInt($run, 'estimated_cost_micros'),
                $costs['reserved_cost_micros'],
                $costs['actual_cost_micros'],
                $this->rowString($run, 'currency'),
                $this->rowInt($run, 'orchestration_version'),
                $this->rowString($run, 'input_hash'),
                $this->jsonHash($this->rowValue($run, 'policy_snapshot')),
                $this->jsonHash($this->rowValue($run, 'capability_snapshot')),
                $this->jsonHash($this->rowValue($run, 'freshness_policy')),
                $this->iso8601($this->rowValue($run, 'queued_at')),
                $this->iso8601($this->rowValue($run, 'started_at')),
                $this->iso8601($this->rowValue($run, 'finished_at')),
                $this->iso8601($this->rowValue($run, 'cancelled_at')),
                $this->iso8601($this->rowValue($run, 'created_at')),
                $this->iso8601($this->rowValue($run, 'updated_at')),
            ];
        }
    }

    /** @return list<string> */
    public function researchInputHeaders(): array
    {
        return [
            'input_id',
            'run_id',
            'input_type',
            'source_observation_id',
            'prior_claim_id',
            'source_policy_hash',
            'llm_use_classification',
            'display_classification',
            'export_classification',
            'observed_at',
            'expires_at',
            'content_hash',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function researchInputRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'account_research_run_inputs',
            $workspaceId,
            [
                'id',
                'research_run_id',
                'input_type',
                'source_observation_id',
                'prior_claim_id',
                'source_policy_hash',
                'llm_use_classification',
                'display_classification',
                'export_classification',
                'observed_at',
                'expires_at',
                'content_hash',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'research_run_id'),
                $this->rowString($row, 'input_type'),
                $this->rowString($row, 'source_observation_id'),
                $this->rowString($row, 'prior_claim_id'),
                $this->rowString($row, 'source_policy_hash'),
                $this->rowString($row, 'llm_use_classification'),
                $this->rowString($row, 'display_classification'),
                $this->rowString($row, 'export_classification'),
                $this->iso8601($this->rowValue($row, 'observed_at')),
                $this->iso8601($this->rowValue($row, 'expires_at')),
                $this->rowString($row, 'content_hash'),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function researchStageHeaders(): array
    {
        return [
            'stage_id',
            'run_id',
            'stage_order',
            'stage_key',
            'state',
            'expected_cost_micros',
            'maximum_cost_micros',
            'implementation_version',
            'checkpoint_hash',
            'safe_error_code',
            'started_at',
            'succeeded_at',
            'failed_at',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function researchStageRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'account_research_stages',
            $workspaceId,
            [
                'id',
                'research_run_id',
                'stage_order',
                'stage_key',
                'state',
                'expected_cost_micros',
                'maximum_cost_micros',
                'implementation_version',
                'checkpoint_hash',
                'safe_error_code',
                'started_at',
                'succeeded_at',
                'failed_at',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'research_run_id'),
                $this->rowInt($row, 'stage_order'),
                $this->rowString($row, 'stage_key'),
                $this->rowString($row, 'state'),
                $this->rowInt($row, 'expected_cost_micros'),
                $this->rowInt($row, 'maximum_cost_micros'),
                $this->rowInt($row, 'implementation_version'),
                $this->rowString($row, 'checkpoint_hash'),
                $this->rowString($row, 'safe_error_code'),
                $this->iso8601($this->rowValue($row, 'started_at')),
                $this->iso8601($this->rowValue($row, 'succeeded_at')),
                $this->iso8601($this->rowValue($row, 'failed_at')),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function researchAttemptHeaders(): array
    {
        return [
            'attempt_id',
            'run_id',
            'stage_id',
            'attempt_number',
            'input_hash',
            'state',
            'safe_error_code',
            'started_at',
            'finished_at',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function researchAttemptRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'account_research_attempts',
            $workspaceId,
            [
                'id',
                'research_run_id',
                'stage_id',
                'attempt_number',
                'input_hash',
                'state',
                'safe_error_code',
                'started_at',
                'finished_at',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'research_run_id'),
                $this->rowString($row, 'stage_id'),
                $this->rowInt($row, 'attempt_number'),
                $this->rowString($row, 'input_hash'),
                $this->rowString($row, 'state'),
                $this->rowString($row, 'safe_error_code'),
                $this->iso8601($this->rowValue($row, 'started_at')),
                $this->iso8601($this->rowValue($row, 'finished_at')),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function researchDispatchHeaders(): array
    {
        return [
            'dispatch_id',
            'run_id',
            'dispatch_kind',
            'stage_key',
            'state',
            'available_at',
            'published_at',
            'publish_attempts',
            'safe_error_code',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function researchDispatchRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'account_research_dispatches',
            $workspaceId,
            [
                'id',
                'research_run_id',
                'dispatch_kind',
                'stage_key',
                'state',
                'available_at',
                'published_at',
                'publish_attempts',
                'safe_error_code',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'research_run_id'),
                $this->rowString($row, 'dispatch_kind'),
                $this->rowString($row, 'stage_key'),
                $this->rowString($row, 'state'),
                $this->iso8601($this->rowValue($row, 'available_at')),
                $this->iso8601($this->rowValue($row, 'published_at')),
                $this->rowInt($row, 'publish_attempts'),
                $this->rowString($row, 'safe_error_code'),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function researchSnapshotHeaders(): array
    {
        return [
            'snapshot_id',
            'account_id',
            'run_id',
            'stage_id',
            'source_type',
            'canonical_public_url',
            'registrable_domain',
            'retrieved_at',
            'observed_at',
            'content_hash',
            'mime_type',
            'byte_count',
            'language',
            'retrieval_capability',
            'retrieval_version',
            'display_classification',
            'llm_use_classification',
            'export_classification',
            'retention_classification',
            'expires_at',
            'redacted_at',
            'state',
            'safe_failure_code',
            'content',
            'content_omission_reason',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function researchSnapshotRows(int $workspaceId): iterable
    {
        if (! $this->hasTable('research_source_snapshots')) {
            return;
        }

        $snapshots = DB::table('research_source_snapshots')
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->lazyById(500, 'id');

        foreach ($snapshots as $snapshot) {
            $exportable = $this->snapshotExportable($snapshot);

            yield [
                $this->rowString($snapshot, 'id'),
                $this->rowInt($snapshot, 'account_id'),
                $this->rowString($snapshot, 'research_run_id'),
                $this->rowString($snapshot, 'stage_id'),
                $this->rowString($snapshot, 'source_type'),
                $exportable ? $this->rowString($snapshot, 'canonical_public_url') : null,
                $exportable ? $this->rowString($snapshot, 'registrable_domain') : null,
                $this->iso8601($this->rowValue($snapshot, 'retrieved_at')),
                $this->iso8601($this->rowValue($snapshot, 'observed_at')),
                $this->rowString($snapshot, 'content_hash'),
                $this->rowString($snapshot, 'mime_type'),
                $this->rowInt($snapshot, 'byte_count'),
                $this->rowString($snapshot, 'language'),
                $this->rowString($snapshot, 'retrieval_capability'),
                $this->rowInt($snapshot, 'retrieval_version'),
                $this->rowString($snapshot, 'display_classification'),
                $this->rowString($snapshot, 'llm_use_classification'),
                $this->rowString($snapshot, 'export_classification'),
                $this->rowString($snapshot, 'retention_classification'),
                $this->iso8601($this->rowValue($snapshot, 'expires_at')),
                $this->iso8601($this->rowValue($snapshot, 'redacted_at')),
                $this->rowString($snapshot, 'state'),
                $this->rowString($snapshot, 'safe_failure_code'),
                $exportable ? $this->rowString($snapshot, 'content') : null,
                $exportable ? null : 'restricted_or_redacted',
                $this->iso8601($this->rowValue($snapshot, 'created_at')),
                $this->iso8601($this->rowValue($snapshot, 'updated_at')),
            ];
        }
    }

    /** @return list<string> */
    public function researchClaimHeaders(): array
    {
        return [
            'claim_id',
            'account_id',
            'run_id',
            'supersedes_claim_id',
            'subject_type',
            'predicate',
            'value_type',
            'value_hash',
            'status',
            'confidence',
            'calibration_band',
            'observed_at',
            'fresh_until',
            'expires_at',
            'extractor_kind',
            'extractor_version',
            'safe_for_message',
            'content_hash',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function researchClaimRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'research_claims',
            $workspaceId,
            [
                'id',
                'account_id',
                'research_run_id',
                'supersedes_claim_id',
                'subject_type',
                'predicate',
                'value_type',
                'value_hash',
                'status',
                'confidence',
                'calibration_band',
                'observed_at',
                'fresh_until',
                'expires_at',
                'extractor_kind',
                'extractor_version',
                'safe_for_message',
                'content_hash',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowInt($row, 'account_id'),
                $this->rowString($row, 'research_run_id'),
                $this->rowString($row, 'supersedes_claim_id'),
                $this->rowString($row, 'subject_type'),
                $this->rowString($row, 'predicate'),
                $this->rowString($row, 'value_type'),
                $this->rowString($row, 'value_hash'),
                $this->rowString($row, 'status'),
                $this->rowValue($row, 'confidence') === null ? null : (string) $this->rowValue($row, 'confidence'),
                $this->rowString($row, 'calibration_band'),
                $this->iso8601($this->rowValue($row, 'observed_at')),
                $this->iso8601($this->rowValue($row, 'fresh_until')),
                $this->iso8601($this->rowValue($row, 'expires_at')),
                $this->rowString($row, 'extractor_kind'),
                $this->rowString($row, 'extractor_version'),
                $this->rowBool($row, 'safe_for_message'),
                $this->rowString($row, 'content_hash'),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function researchEvidenceHeaders(): array
    {
        return [
            'evidence_id',
            'claim_id',
            'snapshot_id',
            'edge_type',
            'locator_hash',
            'quote_hash',
            'evidence_weight',
            'extraction_version',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function researchEvidenceRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'research_claim_evidence',
            $workspaceId,
            [
                'id',
                'claim_id',
                'source_snapshot_id',
                'edge_type',
                'locator_hash',
                'quote_hash',
                'evidence_weight',
                'extraction_version',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'claim_id'),
                $this->rowString($row, 'source_snapshot_id'),
                $this->rowString($row, 'edge_type'),
                $this->rowString($row, 'locator_hash'),
                $this->rowString($row, 'quote_hash'),
                $this->rowValue($row, 'evidence_weight') === null ? null : (string) $this->rowValue($row, 'evidence_weight'),
                $this->rowString($row, 'extraction_version'),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function researchClaimEventHeaders(): array
    {
        return [
            'event_id',
            'account_id',
            'claim_id',
            'action',
            'actor_user_id',
            'actor_display_name',
            'successor_claim_id',
            'prior_status',
            'projected_status',
            'created_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function researchClaimEventRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'research_claim_events',
            $workspaceId,
            [
                'id',
                'account_id',
                'claim_id',
                'action',
                'actor_snapshot',
                'actor_user_id',
                'successor_claim_id',
                'prior_status',
                'projected_status',
                'created_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowInt($row, 'account_id'),
                $this->rowString($row, 'claim_id'),
                $this->rowString($row, 'action'),
                $this->rowInt($row, 'actor_user_id'),
                $this->actorDisplayName($this->rowValue($row, 'actor_snapshot')),
                $this->rowString($row, 'successor_claim_id'),
                $this->rowString($row, 'prior_status'),
                $this->rowString($row, 'projected_status'),
                $this->iso8601($this->rowValue($row, 'created_at')),
            ],
        );
    }

    /**
     * Qualification exports are split into rubric, decision, review and
     * reason sections.  The short names mirror the existing source and
     * integration export API and expose decisions as the primary section.
     *
     * @return list<string>
     */
    public function qualificationHeaders(): array
    {
        return $this->qualificationDecisionHeaders();
    }

    /** @return iterable<int, list<int|string|null>> */
    public function qualificationRows(int $workspaceId): iterable
    {
        yield from $this->qualificationDecisionRows($workspaceId);
    }

    /** @return list<string> */
    public function qualificationRubricHeaders(): array
    {
        return [
            'rubric_id',
            'name',
            'state',
            'current_version_id',
            'lock_version',
            'version_count',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function qualificationRubricRows(int $workspaceId): iterable
    {
        if (! $this->hasTable('qualification_rubrics')) {
            return;
        }

        $rubrics = DB::table('qualification_rubrics')
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->lazyById(500, 'id');

        foreach ($rubrics as $rubric) {
            $versionCount = $this->hasTable('qualification_rubric_versions')
                ? DB::table('qualification_rubric_versions')
                    ->where('workspace_id', $workspaceId)
                    ->where('rubric_id', $rubric->id)
                    ->count()
                : 0;

            yield [
                $this->rowString($rubric, 'id'),
                $this->rowString($rubric, 'name'),
                $this->rowString($rubric, 'state'),
                $this->rowString($rubric, 'current_version_id'),
                $this->rowInt($rubric, 'lock_version'),
                $versionCount,
                $this->iso8601($this->rowValue($rubric, 'created_at')),
                $this->iso8601($this->rowValue($rubric, 'updated_at')),
            ];
        }
    }

    /** @return list<string> */
    public function qualificationRubricVersionHeaders(): array
    {
        return [
            'rubric_version_id',
            'rubric_id',
            'version',
            'schema_version',
            'gtm_profile_version_id',
            'offer_version_id',
            'content_hash',
            'actor_user_id',
            'created_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function qualificationRubricVersionRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'qualification_rubric_versions',
            $workspaceId,
            [
                'id',
                'rubric_id',
                'version',
                'schema_version',
                'gtm_profile_version_id',
                'offer_version_id',
                'content_hash',
                'actor_user_id',
                'created_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'rubric_id'),
                $this->rowInt($row, 'version'),
                $this->rowInt($row, 'schema_version'),
                $this->rowString($row, 'gtm_profile_version_id'),
                $this->rowString($row, 'offer_version_id'),
                $this->rowString($row, 'content_hash'),
                $this->rowInt($row, 'actor_user_id'),
                $this->iso8601($this->rowValue($row, 'created_at')),
            ],
        );
    }

    /** @return list<string> */
    public function qualificationDecisionHeaders(): array
    {
        return [
            'decision_id',
            'account_id',
            'run_id',
            'rubric_version_id',
            'gtm_profile_version_id',
            'offer_version_id',
            'prior_decision_id',
            'input_hash',
            'outcome',
            'confidence',
            'calibration_band',
            'provider_cost_micros',
            'review_state',
            'content_hash',
            'generated_at',
            'reviewed_at',
            'corrected_at',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function qualificationDecisionRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'qualification_decisions',
            $workspaceId,
            [
                'id',
                'account_id',
                'research_run_id',
                'rubric_version_id',
                'gtm_profile_version_id',
                'offer_version_id',
                'prior_decision_id',
                'input_hash',
                'outcome',
                'confidence',
                'calibration_band',
                'provider_cost_micros',
                'review_state',
                'content_hash',
                'generated_at',
                'reviewed_at',
                'corrected_at',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowInt($row, 'account_id'),
                $this->rowString($row, 'research_run_id'),
                $this->rowString($row, 'rubric_version_id'),
                $this->rowString($row, 'gtm_profile_version_id'),
                $this->rowString($row, 'offer_version_id'),
                $this->rowString($row, 'prior_decision_id'),
                $this->rowString($row, 'input_hash'),
                $this->rowString($row, 'outcome'),
                $this->rowValue($row, 'confidence') === null ? null : (string) $this->rowValue($row, 'confidence'),
                $this->rowString($row, 'calibration_band'),
                $this->rowInt($row, 'provider_cost_micros'),
                $this->rowString($row, 'review_state'),
                $this->rowString($row, 'content_hash'),
                $this->iso8601($this->rowValue($row, 'generated_at')),
                $this->iso8601($this->rowValue($row, 'reviewed_at')),
                $this->iso8601($this->rowValue($row, 'corrected_at')),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function qualificationDecisionClaimHeaders(): array
    {
        return ['link_id', 'decision_id', 'claim_id', 'role', 'created_at', 'updated_at'];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function qualificationDecisionClaimRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'qualification_decision_claims',
            $workspaceId,
            ['id', 'decision_id', 'claim_id', 'role', 'created_at', 'updated_at'],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'decision_id'),
                $this->rowString($row, 'claim_id'),
                $this->rowString($row, 'role'),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function qualificationReviewHeaders(): array
    {
        return [
            'review_event_id',
            'account_id',
            'decision_id',
            'action',
            'actor_user_id',
            'actor_display_name',
            'successor_decision_id',
            'successor_run_id',
            'prior_review_state',
            'projected_review_state',
            'created_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function qualificationReviewRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'qualification_review_events',
            $workspaceId,
            [
                'id',
                'account_id',
                'decision_id',
                'action',
                'actor_snapshot',
                'actor_user_id',
                'successor_decision_id',
                'successor_run_id',
                'prior_review_state',
                'projected_review_state',
                'created_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowInt($row, 'account_id'),
                $this->rowString($row, 'decision_id'),
                $this->rowString($row, 'action'),
                $this->rowInt($row, 'actor_user_id'),
                $this->actorDisplayName($this->rowValue($row, 'actor_snapshot')),
                $this->rowString($row, 'successor_decision_id'),
                $this->rowString($row, 'successor_run_id'),
                $this->rowString($row, 'prior_review_state'),
                $this->rowString($row, 'projected_review_state'),
                $this->iso8601($this->rowValue($row, 'created_at')),
            ],
        );
    }

    /** @return list<string> */
    public function reasonToContactHeaders(): array
    {
        return [
            'reason_id',
            'account_id',
            'decision_id',
            'successor_id',
            'confidence',
            'fresh_until',
            'expires_at',
            'safe_for_message',
            'sensitive_inference',
            'prohibited_inference',
            'content_hash',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function reasonToContactRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'reason_to_contact_versions',
            $workspaceId,
            [
                'id',
                'account_id',
                'qualification_decision_id',
                'successor_id',
                'confidence',
                'fresh_until',
                'expires_at',
                'safe_for_message',
                'sensitive_inference',
                'prohibited_inference',
                'content_hash',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowInt($row, 'account_id'),
                $this->rowString($row, 'qualification_decision_id'),
                $this->rowString($row, 'successor_id'),
                $this->rowValue($row, 'confidence') === null ? null : (string) $this->rowValue($row, 'confidence'),
                $this->iso8601($this->rowValue($row, 'fresh_until')),
                $this->iso8601($this->rowValue($row, 'expires_at')),
                $this->rowBool($row, 'safe_for_message'),
                $this->rowBool($row, 'sensitive_inference'),
                $this->rowBool($row, 'prohibited_inference'),
                $this->rowString($row, 'content_hash'),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function reasonToContactClaimHeaders(): array
    {
        return ['link_id', 'reason_id', 'claim_id', 'role', 'created_at', 'updated_at'];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function reasonToContactClaimRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'reason_to_contact_claims',
            $workspaceId,
            ['id', 'reason_to_contact_version_id', 'claim_id', 'role', 'created_at', 'updated_at'],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'reason_to_contact_version_id'),
                $this->rowString($row, 'claim_id'),
                $this->rowString($row, 'role'),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function conversionHeaders(): array
    {
        return $this->conversionEventHeaders();
    }

    /** @return iterable<int, list<int|string|null>> */
    public function conversionRows(int $workspaceId): iterable
    {
        yield from $this->conversionEventRows($workspaceId);
    }

    /** @return list<string> */
    public function conversionConnectionHeaders(): array
    {
        return [
            'connection_id',
            'public_id',
            'kind',
            'display_name',
            'status',
            'current_version_id',
            'version_count',
            'last_used_at',
            'disabled_at',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function conversionConnectionRows(int $workspaceId): iterable
    {
        if (! $this->hasTable('conversion_source_connections')) {
            return;
        }

        $connections = DB::table('conversion_source_connections')
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->lazyById(500, 'id');

        foreach ($connections as $connection) {
            $versionCount = $this->hasTable('conversion_source_connection_versions')
                ? DB::table('conversion_source_connection_versions')
                    ->where('workspace_id', $workspaceId)
                    ->where('connection_id', $connection->id)
                    ->count()
                : 0;

            yield [
                $this->rowString($connection, 'id'),
                $this->rowString($connection, 'public_id'),
                $this->rowString($connection, 'kind'),
                $this->rowString($connection, 'display_name'),
                $this->rowString($connection, 'status'),
                $this->rowString($connection, 'current_version_id'),
                $versionCount,
                $this->iso8601($this->rowValue($connection, 'last_used_at')),
                $this->iso8601($this->rowValue($connection, 'disabled_at')),
                $this->iso8601($this->rowValue($connection, 'created_at')),
                $this->iso8601($this->rowValue($connection, 'updated_at')),
            ];
        }
    }

    /** @return list<string> */
    public function conversionConnectionVersionHeaders(): array
    {
        return [
            'connection_version_id',
            'connection_id',
            'version',
            'status',
            'signature_algorithm',
            'payload_limit_bytes',
            'replay_window_seconds',
            'valid_from',
            'overlap_until',
            'created_by_user_id',
            'created_at',
            'retired_at',
            'wiped_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function conversionConnectionVersionRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'conversion_source_connection_versions',
            $workspaceId,
            [
                'id',
                'connection_id',
                'version',
                'status',
                'signature_algorithm',
                'payload_limit_bytes',
                'replay_window_seconds',
                'valid_from',
                'overlap_until',
                'created_by_user_id',
                'created_at',
                'retired_at',
                'wiped_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'connection_id'),
                $this->rowInt($row, 'version'),
                $this->rowString($row, 'status'),
                $this->rowString($row, 'signature_algorithm'),
                $this->rowInt($row, 'payload_limit_bytes'),
                $this->rowInt($row, 'replay_window_seconds'),
                $this->iso8601($this->rowValue($row, 'valid_from')),
                $this->iso8601($this->rowValue($row, 'overlap_until')),
                $this->rowInt($row, 'created_by_user_id'),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'retired_at')),
                $this->iso8601($this->rowValue($row, 'wiped_at')),
            ],
        );
    }

    /** @return list<string> */
    public function conversionDefinitionHeaders(): array
    {
        return [
            'definition_id',
            'event_key',
            'status',
            'current_version_id',
            'version_count',
            'created_by_user_id',
            'updated_by_user_id',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function conversionDefinitionRows(int $workspaceId): iterable
    {
        if (! $this->hasTable('conversion_definitions')) {
            return;
        }

        $definitions = DB::table('conversion_definitions')
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->lazyById(500, 'id');

        foreach ($definitions as $definition) {
            $versionCount = $this->hasTable('conversion_definition_versions')
                ? DB::table('conversion_definition_versions')
                    ->where('workspace_id', $workspaceId)
                    ->where('conversion_definition_id', $definition->id)
                    ->count()
                : 0;

            yield [
                $this->rowString($definition, 'id'),
                $this->rowString($definition, 'event_key'),
                $this->rowString($definition, 'status'),
                $this->rowString($definition, 'current_version_id'),
                $versionCount,
                $this->rowInt($definition, 'created_by_user_id'),
                $this->rowInt($definition, 'updated_by_user_id'),
                $this->iso8601($this->rowValue($definition, 'created_at')),
                $this->iso8601($this->rowValue($definition, 'updated_at')),
            ];
        }
    }

    /** @return list<string> */
    public function conversionDefinitionVersionHeaders(): array
    {
        return [
            'definition_version_id',
            'definition_id',
            'version',
            'schema_version',
            'display_name',
            'subject_type',
            'dedupe_strategy',
            'content_hash',
            'origin',
            'actor_type',
            'actor_user_id',
            'actor_display_name',
            'created_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function conversionDefinitionVersionRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'conversion_definition_versions',
            $workspaceId,
            [
                'id',
                'conversion_definition_id',
                'version',
                'schema_version',
                'display_name',
                'subject_type',
                'dedupe_strategy',
                'content_hash',
                'origin',
                'actor_type',
                'actor_user_id',
                'actor_display_name',
                'created_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'conversion_definition_id'),
                $this->rowInt($row, 'version'),
                $this->rowInt($row, 'schema_version'),
                $this->rowString($row, 'display_name'),
                $this->rowString($row, 'subject_type'),
                $this->rowString($row, 'dedupe_strategy'),
                $this->rowString($row, 'content_hash'),
                $this->rowString($row, 'origin'),
                $this->rowString($row, 'actor_type'),
                $this->rowInt($row, 'actor_user_id'),
                $this->rowString($row, 'actor_display_name'),
                $this->iso8601($this->rowValue($row, 'created_at')),
            ],
        );
    }

    /** @return list<string> */
    public function conversionEventReceiptHeaders(): array
    {
        return [
            'receipt_id',
            'connection_id',
            'connection_version_id',
            'source_namespace',
            'source_type',
            'dedupe_hash',
            'payload_hash',
            'schema_version',
            'received_at',
            'occurred_at',
            'verification_state',
            'intake_state',
            'safe_rejection_code',
            'identity_expires_at',
            'identity_redacted_at',
            'conversion_event_id',
            'actor_display_name',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function conversionEventReceiptRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'conversion_event_receipts',
            $workspaceId,
            [
                'id',
                'connection_id',
                'connection_version_id',
                'source_namespace',
                'source_type',
                'dedupe_hash',
                'payload_hash',
                'schema_version',
                'received_at',
                'occurred_at',
                'verification_state',
                'intake_state',
                'safe_rejection_code',
                'identity_expires_at',
                'identity_redacted_at',
                'conversion_event_id',
                'actor_display_name',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'connection_id'),
                $this->rowString($row, 'connection_version_id'),
                $this->rowString($row, 'source_namespace'),
                $this->rowString($row, 'source_type'),
                $this->rowString($row, 'dedupe_hash'),
                $this->rowString($row, 'payload_hash'),
                $this->rowInt($row, 'schema_version'),
                $this->iso8601($this->rowValue($row, 'received_at')),
                $this->iso8601($this->rowValue($row, 'occurred_at')),
                $this->rowString($row, 'verification_state'),
                $this->rowString($row, 'intake_state'),
                $this->rowString($row, 'safe_rejection_code'),
                $this->iso8601($this->rowValue($row, 'identity_expires_at')),
                $this->iso8601($this->rowValue($row, 'identity_redacted_at')),
                $this->rowString($row, 'conversion_event_id'),
                $this->rowString($row, 'actor_display_name'),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function conversionEventHeaders(): array
    {
        return [
            'event_id',
            'definition_id',
            'definition_version_id',
            'receipt_id',
            'source_namespace',
            'source_type',
            'occurred_at',
            'received_at',
            'value',
            'currency',
            'verification_state',
            'state',
            'matched_account_id',
            'lineage_root_id',
            'corrects_event_id',
            'reverses_event_id',
            'safe_rejection_code',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function conversionEventRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'conversion_events',
            $workspaceId,
            [
                'id',
                'conversion_definition_id',
                'conversion_definition_version_id',
                'receipt_id',
                'source_namespace',
                'source_type',
                'occurred_at',
                'received_at',
                'value',
                'currency',
                'verification_state',
                'state',
                'matched_account_id',
                'lineage_root_id',
                'corrects_event_id',
                'reverses_event_id',
                'safe_rejection_code',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'conversion_definition_id'),
                $this->rowString($row, 'conversion_definition_version_id'),
                $this->rowString($row, 'receipt_id'),
                $this->rowString($row, 'source_namespace'),
                $this->rowString($row, 'source_type'),
                $this->iso8601($this->rowValue($row, 'occurred_at')),
                $this->iso8601($this->rowValue($row, 'received_at')),
                $this->rowValue($row, 'value') === null ? null : (string) $this->rowValue($row, 'value'),
                $this->rowString($row, 'currency'),
                $this->rowString($row, 'verification_state'),
                $this->rowString($row, 'state'),
                $this->rowInt($row, 'matched_account_id'),
                $this->rowString($row, 'lineage_root_id'),
                $this->rowString($row, 'corrects_event_id'),
                $this->rowString($row, 'reverses_event_id'),
                $this->rowString($row, 'safe_rejection_code'),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function conversionEventMatchHeaders(): array
    {
        return [
            'match_id',
            'event_id',
            'attempt_number',
            'resolution_path',
            'candidate_count',
            'result',
            'matched_account_id',
            'actor_user_id',
            'actor_display_name',
            'created_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function conversionEventMatchRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'conversion_event_matches',
            $workspaceId,
            [
                'id',
                'conversion_event_id',
                'attempt_number',
                'resolution_path',
                'candidate_count',
                'result',
                'matched_account_id',
                'actor_user_id',
                'actor_display_name',
                'created_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'conversion_event_id'),
                $this->rowInt($row, 'attempt_number'),
                $this->rowString($row, 'resolution_path'),
                $this->rowInt($row, 'candidate_count'),
                $this->rowString($row, 'result'),
                $this->rowInt($row, 'matched_account_id'),
                $this->rowInt($row, 'actor_user_id'),
                $this->rowString($row, 'actor_display_name'),
                $this->iso8601($this->rowValue($row, 'created_at')),
            ],
        );
    }

    /** @return list<string> */
    public function conversionEventReviewHeaders(): array
    {
        return [
            'review_event_id',
            'event_id',
            'action',
            'actor_user_id',
            'actor_display_name',
            'prior_state',
            'projected_state',
            'successor_event_id',
            'successor_match_id',
            'created_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function conversionEventReviewRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'conversion_event_review_events',
            $workspaceId,
            [
                'id',
                'conversion_event_id',
                'action',
                'actor_user_id',
                'actor_display_name',
                'prior_state',
                'projected_state',
                'successor_event_id',
                'successor_match_id',
                'created_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'conversion_event_id'),
                $this->rowString($row, 'action'),
                $this->rowInt($row, 'actor_user_id'),
                $this->rowString($row, 'actor_display_name'),
                $this->rowString($row, 'prior_state'),
                $this->rowString($row, 'projected_state'),
                $this->rowString($row, 'successor_event_id'),
                $this->rowString($row, 'successor_match_id'),
                $this->iso8601($this->rowValue($row, 'created_at')),
            ],
        );
    }

    /** @return list<string> */
    public function offerHeaders(): array
    {
        return [
            'offer_id',
            'status',
            'availability_state',
            'current_version_id',
            'current_readiness_evaluation_id',
            'readiness_state',
            'readiness_blocker_count',
            'readiness_evaluated_at',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function offerRows(int $workspaceId): iterable
    {
        if (! $this->hasTable('offers')) {
            return;
        }

        $offers = DB::table('offers')
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->lazyById(500, 'id');

        foreach ($offers as $offer) {
            $readiness = null;
            if ($this->hasTable('offer_readiness_evaluations') && isset($offer->current_readiness_evaluation_id)) {
                $readiness = DB::table('offer_readiness_evaluations')
                    ->where('workspace_id', $workspaceId)
                    ->where('id', $offer->current_readiness_evaluation_id)
                    ->first(['state', 'blockers', 'evaluated_at']);
            }

            yield [
                $this->rowString($offer, 'id'),
                $this->rowString($offer, 'status'),
                $this->rowString($offer, 'availability_state'),
                $this->rowString($offer, 'current_version_id'),
                $this->rowString($offer, 'current_readiness_evaluation_id'),
                $readiness?->state,
                $this->jsonCount($readiness?->blockers),
                $this->iso8601($readiness?->evaluated_at),
                $this->iso8601($this->rowValue($offer, 'created_at')),
                $this->iso8601($this->rowValue($offer, 'updated_at')),
            ];
        }
    }

    /** @return list<string> */
    public function offerVersionHeaders(): array
    {
        return [
            'offer_version_id',
            'offer_id',
            'version',
            'schema_version',
            'status_snapshot',
            'content_hash',
            'origin',
            'from_status',
            'actor_type',
            'actor_user_id',
            'actor_display_name',
            'created_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function offerVersionRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'offer_versions',
            $workspaceId,
            [
                'id',
                'offer_id',
                'version',
                'schema_version',
                'status_snapshot',
                'content_hash',
                'origin',
                'from_status',
                'actor_type',
                'actor_user_id',
                'actor_display_name',
                'created_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'offer_id'),
                $this->rowInt($row, 'version'),
                $this->rowInt($row, 'schema_version'),
                $this->rowString($row, 'status_snapshot'),
                $this->rowString($row, 'content_hash'),
                $this->rowString($row, 'origin'),
                $this->rowString($row, 'from_status'),
                $this->rowString($row, 'actor_type'),
                $this->rowInt($row, 'actor_user_id'),
                $this->rowString($row, 'actor_display_name'),
                $this->iso8601($this->rowValue($row, 'created_at')),
            ],
        );
    }

    /** @return list<string> */
    public function offerVersionAssetHeaders(): array
    {
        return [
            'link_id',
            'offer_id',
            'offer_version_id',
            'asset_id',
            'asset_version_id',
            'role',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function offerVersionAssetRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'offer_version_assets',
            $workspaceId,
            [
                'id',
                'offer_id',
                'offer_version_id',
                'asset_id',
                'asset_version_id',
                'role',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowInt($row, 'id'),
                $this->rowString($row, 'offer_id'),
                $this->rowString($row, 'offer_version_id'),
                $this->rowString($row, 'asset_id'),
                $this->rowString($row, 'asset_version_id'),
                $this->rowString($row, 'role'),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function offerReadinessHeaders(): array
    {
        return [
            'evaluation_id',
            'offer_id',
            'offer_version_id',
            'evaluator_version',
            'state',
            'blocker_count',
            'dependency_hash',
            'trigger',
            'evaluated_at',
            'valid_until',
            'created_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function offerReadinessRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'offer_readiness_evaluations',
            $workspaceId,
            [
                'id',
                'offer_id',
                'offer_version_id',
                'evaluator_version',
                'state',
                'blockers',
                'dependency_hash',
                'trigger',
                'evaluated_at',
                'valid_until',
                'created_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'offer_id'),
                $this->rowString($row, 'offer_version_id'),
                $this->rowInt($row, 'evaluator_version'),
                $this->rowString($row, 'state'),
                $this->jsonCount($this->rowValue($row, 'blockers')),
                $this->rowString($row, 'dependency_hash'),
                $this->rowString($row, 'trigger'),
                $this->iso8601($this->rowValue($row, 'evaluated_at')),
                $this->iso8601($this->rowValue($row, 'valid_until')),
                $this->iso8601($this->rowValue($row, 'created_at')),
            ],
        );
    }

    /** @return list<string> */
    public function offerVersionReviewHeaders(): array
    {
        return [
            'review_event_id',
            'offer_id',
            'offer_version_id',
            'action',
            'from_state',
            'to_state',
            'actor_user_id',
            'actor_display_name',
            'policy_hash',
            'created_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function offerVersionReviewRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'offer_version_review_events',
            $workspaceId,
            [
                'id',
                'offer_id',
                'offer_version_id',
                'action',
                'from_state',
                'to_state',
                'actor_user_id',
                'actor_display_name',
                'policy_hash',
                'created_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'offer_id'),
                $this->rowString($row, 'offer_version_id'),
                $this->rowString($row, 'action'),
                $this->rowString($row, 'from_state'),
                $this->rowString($row, 'to_state'),
                $this->rowInt($row, 'actor_user_id'),
                $this->rowString($row, 'actor_display_name'),
                $this->rowString($row, 'policy_hash'),
                $this->iso8601($this->rowValue($row, 'created_at')),
            ],
        );
    }

    /** @return list<string> */
    public function assetHeaders(): array
    {
        return [
            'asset_id',
            'status',
            'current_version_id',
            'lock_version',
            'version_count',
            'created_by_user_id',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function assetRows(int $workspaceId): iterable
    {
        if (! $this->hasTable('assets')) {
            return;
        }

        $assets = DB::table('assets')
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->lazyById(500, 'id');

        foreach ($assets as $asset) {
            $versionCount = $this->hasTable('asset_versions')
                ? DB::table('asset_versions')
                    ->where('workspace_id', $workspaceId)
                    ->where('asset_id', $asset->id)
                    ->count()
                : 0;

            yield [
                $this->rowString($asset, 'id'),
                $this->rowString($asset, 'status'),
                $this->rowString($asset, 'current_version_id'),
                $this->rowInt($asset, 'lock_version'),
                $versionCount,
                $this->rowInt($asset, 'created_by_user_id'),
                $this->iso8601($this->rowValue($asset, 'created_at')),
                $this->iso8601($this->rowValue($asset, 'updated_at')),
            ];
        }
    }

    /** @return list<string> */
    public function assetVersionHeaders(): array
    {
        return [
            'asset_version_id',
            'asset_id',
            'version',
            'title',
            'media_type',
            'source',
            'content_hash',
            'approval_state',
            'expires_at',
            'availability_state',
            'licence',
            'classification',
            'actor_user_id',
            'actor_display_name',
            'created_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function assetVersionRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'asset_versions',
            $workspaceId,
            [
                'id',
                'asset_id',
                'version',
                'title',
                'media_type',
                'source',
                'content_hash',
                'approval_state',
                'expires_at',
                'availability_state',
                'licence',
                'classification',
                'actor_user_id',
                'actor_display_name',
                'created_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'asset_id'),
                $this->rowInt($row, 'version'),
                $this->rowString($row, 'title'),
                $this->rowString($row, 'media_type'),
                $this->rowString($row, 'source'),
                $this->rowString($row, 'content_hash'),
                $this->rowString($row, 'approval_state'),
                $this->iso8601($this->rowValue($row, 'expires_at')),
                $this->rowString($row, 'availability_state'),
                $this->rowString($row, 'licence'),
                $this->rowString($row, 'classification'),
                $this->rowInt($row, 'actor_user_id'),
                $this->rowString($row, 'actor_display_name'),
                $this->iso8601($this->rowValue($row, 'created_at')),
            ],
        );
    }

    /** @return list<string> */
    public function fulfilmentHeaders(): array
    {
        return [
            'request_id',
            'offer_id',
            'offer_version_id',
            'conversion_event_id',
            'account_id',
            'capability_id',
            'due_at',
            'assigned_user_id',
            'state',
            'safe_failure_code',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function fulfilmentRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'fulfilment_requests',
            $workspaceId,
            [
                'id',
                'offer_id',
                'offer_version_id',
                'conversion_event_id',
                'account_id',
                'fulfilment_capability_id',
                'due_at',
                'assigned_user_id',
                'state',
                'safe_failure_code',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'offer_id'),
                $this->rowString($row, 'offer_version_id'),
                $this->rowString($row, 'conversion_event_id'),
                $this->rowInt($row, 'account_id'),
                $this->rowString($row, 'fulfilment_capability_id'),
                $this->iso8601($this->rowValue($row, 'due_at')),
                $this->rowInt($row, 'assigned_user_id'),
                $this->rowString($row, 'state'),
                $this->rowString($row, 'safe_failure_code'),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function fulfilmentCapabilityHeaders(): array
    {
        return [
            'capability_id',
            'display_name',
            'mode',
            'status',
            'configuration_version',
            'response_sla_hours',
            'capacity_limit',
            'capacity_window',
            'health_state',
            'last_checked_at',
            'safe_blocker_code',
            'owner_user_id',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function fulfilmentCapabilityRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'fulfilment_capabilities',
            $workspaceId,
            [
                'id',
                'display_name',
                'mode',
                'status',
                'configuration_version',
                'response_sla_hours',
                'capacity_limit',
                'capacity_window',
                'health_state',
                'last_checked_at',
                'safe_blocker_code',
                'owner_user_id',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'display_name'),
                $this->rowString($row, 'mode'),
                $this->rowString($row, 'status'),
                $this->rowInt($row, 'configuration_version'),
                $this->rowInt($row, 'response_sla_hours'),
                $this->rowInt($row, 'capacity_limit'),
                $this->rowString($row, 'capacity_window'),
                $this->rowString($row, 'health_state'),
                $this->iso8601($this->rowValue($row, 'last_checked_at')),
                $this->rowString($row, 'safe_blocker_code'),
                $this->rowInt($row, 'owner_user_id'),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function fulfilmentReservationHeaders(): array
    {
        return [
            'reservation_id',
            'request_id',
            'capability_id',
            'capacity_window_id',
            'units',
            'state',
            'reserved_at',
            'released_at',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function fulfilmentReservationRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'fulfilment_capacity_reservations',
            $workspaceId,
            [
                'id',
                'fulfilment_request_id',
                'fulfilment_capability_id',
                'capacity_window_id',
                'units',
                'state',
                'reserved_at',
                'released_at',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowString($row, 'id'),
                $this->rowString($row, 'fulfilment_request_id'),
                $this->rowString($row, 'fulfilment_capability_id'),
                $this->rowInt($row, 'capacity_window_id'),
                $this->rowInt($row, 'units'),
                $this->rowString($row, 'state'),
                $this->iso8601($this->rowValue($row, 'reserved_at')),
                $this->iso8601($this->rowValue($row, 'released_at')),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /** @return list<string> */
    public function fulfilmentCapacityWindowHeaders(): array
    {
        return [
            'capacity_window_id',
            'capability_id',
            'window_key',
            'limit_units',
            'reserved_units',
            'consumed_units',
            'created_at',
            'updated_at',
        ];
    }

    /** @return iterable<int, list<int|string|null>> */
    public function fulfilmentCapacityWindowRows(int $workspaceId): iterable
    {
        yield from $this->safeTableRows(
            'fulfilment_capacity_windows',
            $workspaceId,
            [
                'id',
                'fulfilment_capability_id',
                'window_key',
                'limit_units',
                'reserved_units',
                'consumed_units',
                'created_at',
                'updated_at',
            ],
            fn (object $row): array => [
                $this->rowInt($row, 'id'),
                $this->rowString($row, 'fulfilment_capability_id'),
                $this->rowString($row, 'window_key'),
                $this->rowInt($row, 'limit_units'),
                $this->rowInt($row, 'reserved_units'),
                $this->rowInt($row, 'consumed_units'),
                $this->iso8601($this->rowValue($row, 'created_at')),
                $this->iso8601($this->rowValue($row, 'updated_at')),
            ],
        );
    }

    /**
     * Run a bounded, workspace-scoped query and map each row without loading
     * the full aggregate into memory.  The callback only receives explicitly
     * selected columns, which makes accidental secret export harder to add.
     *
     * @param  list<string>  $columns
     * @param  callable(object): list<int|string|null>  $map
     * @return iterable<int, list<int|string|null>>
     */
    private function safeTableRows(string $table, int $workspaceId, array $columns, callable $map): iterable
    {
        if (! $this->hasTable($table)) {
            return;
        }

        $rows = DB::table($table)
            ->where('workspace_id', $workspaceId)
            ->select($columns)
            ->orderBy('id')
            ->lazyById(500, 'id');

        foreach ($rows as $row) {
            yield $map($row);
        }
    }

    private function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    private function rowValue(object $row, string $column): mixed
    {
        return isset($row->{$column}) ? $row->{$column} : null;
    }

    private function rowString(object $row, string $column): ?string
    {
        $value = $this->rowValue($row, $column);

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private function rowInt(object $row, string $column): ?int
    {
        $value = $this->rowValue($row, $column);

        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function rowBool(object $row, string $column): ?int
    {
        $value = $this->rowValue($row, $column);

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes'], true) ? 1 : 0;
        }

        return (int) (bool) $value;
    }

    private function actorDisplayName(mixed $snapshot): ?string
    {
        if (is_string($snapshot)) {
            try {
                $snapshot = json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }

        if (! is_array($snapshot)) {
            return null;
        }

        $displayName = $snapshot['display_name'] ?? null;

        return is_string($displayName) ? mb_substr($displayName, 0, 160) : null;
    }

    private function jsonHash(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return hash('sha256', $value);
        }

        try {
            return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return null;
        }
    }

    private function jsonCount(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return 0;
            }
        }

        return is_countable($value) ? count($value) : 0;
    }

    private function snapshotExportable(object $snapshot): bool
    {
        if (in_array($this->rowString($snapshot, 'export_classification'), ['restricted', 'prohibited'], true)) {
            return false;
        }

        if (! in_array($this->rowString($snapshot, 'state'), ['available', 'partial'], true)) {
            return false;
        }

        if ($this->rowValue($snapshot, 'redacted_at') !== null) {
            return false;
        }

        $expiresAt = $this->rowValue($snapshot, 'expires_at');
        if ($expiresAt !== null) {
            try {
                if (Carbon::parse($expiresAt)->isPast()) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<int|string|null>>  $rows
     * @return list<array<string, int|string|null>>
     */
    public function records(array $headers, iterable $rows, ?int $limit = null): array
    {
        $records = [];

        foreach ($rows as $row) {
            $records[] = array_combine($headers, $row) ?: [];

            if ($limit !== null && count($records) >= $limit) {
                break;
            }
        }

        return $records;
    }

    private function relatedScalar(mixed $related, string $attribute): int|string|null
    {
        if (! $related instanceof Model) {
            return null;
        }

        return $this->scalar($related->getAttribute($attribute));
    }

    private function scalar(mixed $value): int|string|null
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_int($value) || is_string($value)) {
            return $value;
        }

        return null;
    }

    private function iso8601(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::parse($value)->toIso8601String();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toIso8601String();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
