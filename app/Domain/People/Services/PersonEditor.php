<?php

namespace App\Domain\People\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Campaigns\Services\RedactGovernedCampaignRecipients;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Objectives\Services\CancelObjectiveRun;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Models\Account;
use App\Models\ObjectiveRun;
use App\Models\Person;
use App\Models\ProspectSource;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PersonEditor
{
    public function __construct(
        private readonly ProspectIngestionService $ingestion,
        private readonly CancelObjectiveRun $cancelObjectiveRun,
        private readonly RedactGovernedCampaignRecipients $governedRecipients,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        Workspace $workspace,
        User $actor,
        array $data,
        ?string $idempotencyKey = null,
        ?string $sourceKey = null,
    ): Person {
        $sourceKey ??= 'manual';
        $person = new Person([
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $actor->id,
            'source' => Person::SOURCE_MANUAL,
        ]);

        $isReplay = $idempotencyKey !== null && $this->hasIngestionRun($workspace, $idempotencyKey, $sourceKey);
        if (! $isReplay) {
            $this->fill($person, $data);
        }

        if ($isReplay) {
            $fullName = $this->nullableString($data['full_name'] ?? null);
            $email = trim((string) ($data['email'] ?? ''));
            $accountId = $data['account_id'] ?? null;
            $personData = [
                'account_id' => $accountId === null || $accountId === ''
                    ? null
                    : (int) $accountId,
                'full_name' => $fullName,
                // The first ingestion call includes both derived fields. Bind
                // retries to that same canonical payload so a matching key
                // deterministically returns the stored person.
                'first_name' => EmailNormalizer::firstName($fullName),
                'email_address' => $email,
                'email_normalized' => EmailNormalizer::normalize($email),
                'job_title' => $this->nullableString($data['job_title'] ?? null),
                'notes' => $this->nullableString($data['notes'] ?? null),
                'tags' => $this->normalisedTags(is_array($data['tags'] ?? null) ? $data['tags'] : null),
            ];

            return $this->ingestion->createManualPerson(
                workspace: $workspace,
                actor: $actor,
                record: $personData,
                idempotencyKey: $idempotencyKey,
                sourceKey: $sourceKey,
            );
        }

        return $this->ingestion->createManualPerson(
            workspace: $workspace,
            actor: $actor,
            record: [
                'account_id' => $person->account_id,
                'full_name' => $person->full_name,
                'first_name' => $person->first_name,
                'email_address' => $person->email_address,
                'email_normalized' => $person->email_normalized,
                'job_title' => $person->job_title,
                'notes' => $person->notes,
                'tags' => $person->tags ?? [],
            ],
            idempotencyKey: $idempotencyKey,
            sourceKey: $sourceKey,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Person $person, array $data): Person
    {
        // Email edits must not rewrite campaign_recipients, threads, or
        // messages — those remain the enrolment/send-time snapshot (SC-03).
        $this->fill($person, $data);
        $this->save($person);

        return $person->fresh();
    }

    public function delete(Person $person, User $actor): void
    {
        DB::transaction(function () use ($person, $actor): void {
            $locked = Person::query()->whereKey($person->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $workspaceId = (int) $locked->workspace_id;
            $personId = (int) $locked->getKey();
            $memberIds = $this->objectiveMemberIds($workspaceId, $personId);

            // Canonical deletion is an explicit, narrowly scoped exception to
            // the compiled-input freeze. Stop and redact the executable copy
            // before the Person FK could null it implicitly.
            $this->governedRecipients->forPerson($workspaceId, $personId);

            // Resolve the run graph before detaching its Person/member links.
            // Otherwise the deletion can leave an executable plan behind or
            // fail on the plan action's restrictive Person foreign key.
            $this->invalidateObjectivePlans($actor, $workspaceId, $personId, $memberIds);

            if (Schema::hasTable('audience_snapshot_members')) {
                if ($memberIds !== [] && Schema::hasTable('gtm_experiment_assignment_members')) {
                    $assignmentIds = DB::table('gtm_experiment_assignment_members')
                        ->where('workspace_id', $workspaceId)
                        ->whereIn('audience_snapshot_member_id', $memberIds)
                        ->pluck('gtm_experiment_assignment_id');
                    DB::table('gtm_experiment_assignment_members')
                        ->where('workspace_id', $workspaceId)
                        ->whereIn('audience_snapshot_member_id', $memberIds)
                        ->update(['person_id' => null]);
                    if ($assignmentIds->isNotEmpty() && Schema::hasTable('gtm_experiment_assignments') && Schema::hasTable('gtm_experiments')) {
                        $experimentIds = DB::table('gtm_experiment_assignments')
                            ->whereIn('id', $assignmentIds->all())
                            ->pluck('gtm_experiment_id');
                        DB::table('gtm_experiments')
                            ->where('workspace_id', $workspaceId)
                            ->whereIn('id', $experimentIds->all())
                            ->whereIn('status', ['assigned', 'approved', 'running'])
                            ->update(['status' => 'paused', 'updated_at' => now()]);
                    }
                }
                if ($memberIds !== [] && Schema::hasTable('audience_snapshot_member_sources')) {
                    DB::table('audience_snapshot_member_sources')
                        ->where('workspace_id', $workspaceId)
                        ->whereIn('audience_snapshot_member_id', $memberIds)
                        ->update([
                            'person_source_membership_id' => null,
                            'rights_result' => 'person_deleted',
                        ]);
                }
                DB::table('audience_snapshot_members')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('id', $memberIds)
                    ->update([
                        'person_id' => null,
                        'qualification_decision_id' => null,
                        'reason_to_contact_version_id' => null,
                        'criterion_results' => json_encode([], JSON_THROW_ON_ERROR),
                        'inclusion_reasons' => json_encode([], JSON_THROW_ON_ERROR),
                        'result' => 'excluded',
                        'exclusion_reasons' => json_encode(['person_deleted'], JSON_THROW_ON_ERROR),
                        'missing_fields' => json_encode([], JSON_THROW_ON_ERROR),
                        'suppression_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
                        'evidence_manifest' => json_encode([], JSON_THROW_ON_ERROR),
                        'evidence_fresh_until' => null,
                    ]);
            }

            // Keep source observations for audit/retention, while removing the
            // restrictive canonical membership projection before the legacy
            // person delete operation.
            $locked->sourceMemberships()->delete();
            $locked->delete();
        }, 3);
    }

    /** @return list<string> */
    private function objectiveMemberIds(int $workspaceId, int $personId): array
    {
        if (! Schema::hasTable('audience_snapshot_members')) {
            return [];
        }

        $memberIds = DB::table('audience_snapshot_members')
            ->where('workspace_id', $workspaceId)
            ->where('person_id', $personId)
            ->pluck('id');

        if (Schema::hasTable('gtm_experiment_assignment_members')) {
            $memberIds = $memberIds->merge(DB::table('gtm_experiment_assignment_members')
                ->where('workspace_id', $workspaceId)
                ->where('person_id', $personId)
                ->pluck('audience_snapshot_member_id'));
        }
        if (Schema::hasTable('execution_plan_actions')) {
            $memberIds = $memberIds->merge(DB::table('execution_plan_actions')
                ->where('workspace_id', $workspaceId)
                ->where('person_id', $personId)
                ->pluck('audience_snapshot_member_id'));
        }

        /** @var list<string> $ids */
        $ids = $memberIds
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $ids;
    }

    /**
     * Cancel and permanently invalidate every plan containing the Person.
     * Safe hashes and lineage remain, but exact recipient content does not.
     *
     * @param  list<string>  $memberIds
     */
    private function invalidateObjectivePlans(User $actor, int $workspaceId, int $personId, array $memberIds): void
    {
        if (! Schema::hasTable('objective_runs')) {
            return;
        }

        $runIds = collect();
        if ($memberIds !== []) {
            $snapshotIds = DB::table('audience_snapshot_members')
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $memberIds)
                ->pluck('audience_snapshot_id');
            if ($snapshotIds->isNotEmpty()) {
                $runIds = $runIds->merge(DB::table('objective_runs')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('audience_snapshot_id', $snapshotIds->all())
                    ->pluck('id'));
            }
        }

        if (Schema::hasTable('execution_plan_actions') && Schema::hasTable('execution_plans')) {
            $personPlanIds = DB::table('execution_plan_actions')
                ->where('workspace_id', $workspaceId)
                ->where(function ($query) use ($personId, $memberIds): void {
                    $query->where('person_id', $personId);
                    if ($memberIds !== []) {
                        $query->orWhereIn('audience_snapshot_member_id', $memberIds);
                    }
                })
                ->pluck('execution_plan_id');
            if ($personPlanIds->isNotEmpty()) {
                $runIds = $runIds->merge(DB::table('execution_plans')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('id', $personPlanIds->all())
                    ->pluck('objective_run_id'));
            }
        }

        /** @var list<string> $runIdList */
        $runIdList = $runIds
            ->filter(fn (mixed $runId): bool => is_string($runId) && $runId !== '')
            ->map(fn (mixed $runId): string => (string) $runId)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($runIdList === []) {
            return;
        }

        $workspace = Workspace::query()->whereKey($workspaceId)->firstOrFail();
        if (! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can delete a Person referenced by Objective runs or plans.');
        }

        foreach ($runIdList as $runId) {
            $run = ObjectiveRun::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($runId)
                ->first();
            if ($run !== null) {
                $this->cancelObjectiveRun->handle(
                    $run,
                    $actor,
                    'Person deletion invalidated the frozen audience and exact plan.',
                );
            }
        }

        if ($this->hasUnresolvedProviderWork($workspaceId, $runIdList)) {
            // The outer deletion transaction rolls the cancellation and all
            // redaction work back. Reconciliation owns this provider result.
            throw ValidationException::withMessages([
                'person' => 'Person deletion is waiting for in-flight Objective provider work to reconcile. Retry after reconciliation.',
            ]);
        }

        if (Schema::hasTable('objective_run_arm_drafts')) {
            DB::table('objective_run_arm_drafts')
                ->where('workspace_id', $workspaceId)
                ->whereIn('objective_run_id', $runIdList)
                ->update([
                    'state' => 'cancelled',
                    'subject_template' => null,
                    'body_template' => null,
                    'claim_manifest' => null,
                    'cta_manifest' => null,
                    'safe_error_code' => 'person_deleted',
                    'safe_error_detail' => 'Exact drafting content was redacted because a Person in the frozen audience was deleted.',
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        if (! Schema::hasTable('execution_plans') || ! Schema::hasTable('execution_plan_actions')) {
            return;
        }

        $allPlanIds = DB::table('execution_plans')
            ->where('workspace_id', $workspaceId)
            ->whereIn('objective_run_id', $runIdList)
            ->pluck('id');
        if ($allPlanIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('objectives')) {
            DB::table('objectives')
                ->where('workspace_id', $workspaceId)
                ->whereIn('current_plan_id', $allPlanIds->all())
                ->update(['current_plan_id' => null, 'updated_at' => now()]);
        }
        DB::table('execution_plan_actions')
            ->where('workspace_id', $workspaceId)
            ->whereIn('execution_plan_id', $allPlanIds->all())
            ->update(['state' => 'cancelled', 'updated_at' => now()]);
        DB::table('execution_plan_actions')
            ->where('workspace_id', $workspaceId)
            ->whereIn('execution_plan_id', $allPlanIds->all())
            ->where(function ($query) use ($personId, $memberIds): void {
                $query->where('person_id', $personId);
                if ($memberIds !== []) {
                    $query->orWhereIn('audience_snapshot_member_id', $memberIds);
                }
            })
            ->update([
                'person_id' => null,
                'destination_email_address' => null,
                'subject' => null,
                'body_text' => null,
                'body_html' => null,
                'updated_at' => now(),
            ]);
        DB::table('execution_plans')
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $allPlanIds->all())
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /** @param  list<string>  $runIds */
    private function hasUnresolvedProviderWork(int $workspaceId, array $runIds): bool
    {
        if ($runIds === [] || ! Schema::hasTable('objective_run_arm_drafts') || ! Schema::hasTable('ai_provider_operations')) {
            return false;
        }

        return DB::table('objective_run_arm_drafts as drafts')
            ->join('ai_provider_operations as operations', 'operations.id', '=', 'drafts.ai_provider_operation_id')
            ->where('drafts.workspace_id', $workspaceId)
            ->where('operations.workspace_id', $workspaceId)
            ->whereIn('drafts.objective_run_id', $runIds)
            ->whereIn('operations.status', [
                AiOperationStatus::Executing->value,
                AiOperationStatus::Uncertain->value,
                AiOperationStatus::Reconciling->value,
            ])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(Person $person, array $data): void
    {
        $email = trim((string) $data['email']);

        if (! EmailNormalizer::isValid($email)) {
            throw ValidationException::withMessages([
                'email' => 'Enter a valid email address.',
            ]);
        }

        $normalized = EmailNormalizer::normalize($email);
        $workspaceId = (int) $person->workspace_id;
        $conflict = $this->personUsingEmail($workspaceId, $normalized, $person->id);

        if ($conflict !== null) {
            throw ValidationException::withMessages([
                'email' => $this->emailConflictMessage($conflict, $normalized),
            ]);
        }

        $accountId = $this->resolvedAccountId($workspaceId, $data['account_id'] ?? null);
        $fullName = $this->nullableString($data['full_name'] ?? null);
        $tags = $data['tags'] ?? null;

        $person->fill([
            'account_id' => $accountId,
            'full_name' => $fullName,
            'first_name' => EmailNormalizer::firstName($fullName),
            'email_address' => $email,
            'email_normalized' => $normalized,
            'job_title' => $this->nullableString($data['job_title'] ?? null),
            'notes' => $this->nullableString($data['notes'] ?? null),
            'tags' => $this->normalisedTags(is_array($tags) ? $tags : null),
        ]);
    }

    private function save(Person $person): void
    {
        try {
            $person->save();
        } catch (UniqueConstraintViolationException) {
            $normalized = (string) $person->email_normalized;
            $conflict = $this->personUsingEmail((int) $person->workspace_id, $normalized, $person->id);

            throw ValidationException::withMessages([
                'email' => $conflict instanceof Person
                    ? $this->emailConflictMessage($conflict, $normalized)
                    : "Another person already uses {$normalized}.",
            ]);
        }
    }

    private function personUsingEmail(int $workspaceId, string $email, ?int $exceptId): ?Person
    {
        return Person::query()
            ->where('workspace_id', $workspaceId)
            ->where('email_normalized', $email)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->first();
    }

    private function hasIngestionRun(Workspace $workspace, string $idempotencyKey, string $sourceKey): bool
    {
        $sourceId = ProspectSource::query()
            ->where('workspace_id', $workspace->id)
            ->where('system_key', $sourceKey)
            ->value('id');

        return $sourceId !== null && SourceRun::query()
            ->where('workspace_id', $workspace->id)
            ->where('prospect_source_id', $sourceId)
            ->where('idempotency_key', $idempotencyKey)
            ->exists();
    }

    private function emailConflictMessage(Person $person, string $email): string
    {
        $name = $this->nullableString($person->full_name)
            ?? $this->nullableString($person->first_name)
            ?? $email;

        return "Another person, ‘{$name}’, already uses {$email}.";
    }

    private function resolvedAccountId(int $workspaceId, mixed $accountId): ?int
    {
        if ($accountId === null || $accountId === '') {
            return null;
        }

        $id = (int) $accountId;
        $exists = Account::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($id)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'account_id' => 'Choose an account from this workspace.',
            ]);
        }

        return $id;
    }

    /**
     * @param  array<int, mixed>|null  $tags
     * @return array<int, string>
     */
    private function normalisedTags(?array $tags): array
    {
        $normalised = [];

        foreach ($tags ?? [] as $tag) {
            $value = trim((string) $tag);

            if ($value === '' || in_array($value, $normalised, true)) {
                continue;
            }

            $normalised[] = $value;

            if (count($normalised) === 20) {
                break;
            }
        }

        return $normalised;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
