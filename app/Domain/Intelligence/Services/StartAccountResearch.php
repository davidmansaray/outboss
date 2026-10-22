<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Intelligence\DTOs\AccountResearchPreflight;
use App\Domain\Intelligence\DTOs\StartAccountResearchRequest;
use App\Domain\Intelligence\Exceptions\AccountResearchBusy;
use App\Domain\Intelligence\Exceptions\AccountResearchConflict;
use App\Domain\Intelligence\Exceptions\AccountResearchPaused;
use App\Domain\Intelligence\Support\CanonicalIntelligence;
use App\Models\Account;
use App\Models\AccountResearchDispatch;
use App\Models\AccountResearchRun;
use App\Models\AccountResearchRunInput;
use App\Models\AccountResearchStage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Freeze one Account's research inputs and create its durable orchestration
 * graph. The worker receives only the resulting run/stage IDs.
 */
final class StartAccountResearch
{
    public function __construct(
        private readonly PreflightAccountResearch $preflight,
        private readonly AiBudgetGateway $budget,
    ) {}

    /**
     * This intentionally accepts the DTO as well as scalar arguments so REST,
     * browser and internal callers share exactly one write boundary.
     *
     * @param  array<string,mixed>  $freshnessPolicy
     * @param  list<string>  $capabilities
     */
    public function handle(
        Workspace|StartAccountResearchRequest $workspace,
        Account|int|string|null $account = null,
        ?User $actor = null,
        ?string $idempotencyKey = null,
        ?string $gtmProfileVersionId = null,
        ?string $offerVersionId = null,
        array $freshnessPolicy = [],
        array $capabilities = [],
        ?int $maximumCostMicros = null,
        ?string $preflightHash = null,
    ): AccountResearchRun {
        $request = $workspace instanceof StartAccountResearchRequest
            ? $workspace
            : new StartAccountResearchRequest(
                workspace: $workspace,
                account: $account ?? throw ValidationException::withMessages(['account' => 'An Account is required.']),
                actor: $actor,
                idempotencyKey: $idempotencyKey ?? '',
                gtmProfileVersionId: $gtmProfileVersionId,
                offerVersionId: $offerVersionId,
                freshnessPolicy: $freshnessPolicy,
                capabilities: $capabilities,
                maximumCostMicros: $maximumCostMicros ?? 1_500_000,
                preflightHash: $preflightHash,
            );

        $this->assertIdempotencyKey($request->idempotencyKey);
        if ($request->actor !== null && ! $request->workspace->isOwner($request->actor)) {
            throw new AuthorizationException('Only the workspace owner can start Account research.');
        }
        if ((bool) config('intelligence.research.pause_new', false)) {
            throw new AccountResearchPaused;
        }

        // This is an intentionally side-effect-free preflight. The identical
        // call is repeated after locking the Account before any insert.
        $preview = $this->preflight->handle(
            $request->workspace,
            $request->account,
            $request->gtmProfileVersionId,
            $request->offerVersionId,
            $request->freshnessPolicy,
            $request->capabilities,
            $request->maximumCostMicros,
        );
        if ($request->preflightHash !== null && ! hash_equals($preview->inputHash, $request->preflightHash)) {
            throw new AccountResearchConflict('The preflight inputs have changed; request a new preflight.');
        }
        if ($preview->blocked && $this->hasHardBlocker($preview->blockers)) {
            throw ValidationException::withMessages([
                'research' => 'The research request is blocked by its preflight checks.',
            ]);
        }

        return DB::transaction(function () use ($request, $preview): AccountResearchRun {
            $workspaceModel = Workspace::query()->whereKey($request->workspace->id)->lockForUpdate()->firstOrFail();
            $accountModel = $this->preflight->account($workspaceModel, $request->account);
            $lockedPreview = $this->preflight->handle(
                $workspaceModel,
                $accountModel,
                $request->gtmProfileVersionId,
                $request->offerVersionId,
                $request->freshnessPolicy,
                $request->capabilities,
                $request->maximumCostMicros,
            );
            if (! hash_equals($preview->inputHash, $lockedPreview->inputHash)) {
                throw new AccountResearchConflict('The Account or research policy changed after preflight.');
            }

            $existing = AccountResearchRun::query()
                ->where('workspace_id', $workspaceModel->id)
                ->where('account_id', $accountModel->id)
                ->where('idempotency_key', $request->idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->input_hash, $lockedPreview->inputHash)) {
                    throw new AccountResearchConflict;
                }

                return $existing;
            }

            $this->assertNoActiveRun($workspaceModel, $accountModel);
            $state = $this->initialState($lockedPreview->blockers);
            $run = AccountResearchRun::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceModel->id,
                'account_id' => $accountModel->id,
                'gtm_profile_version_id' => $lockedPreview->gtmProfileVersionId,
                'offer_version_id' => $lockedPreview->offerVersionId,
                'parent_run_id' => $request->parentRunId,
                'account_snapshot' => $lockedPreview->accountSnapshot,
                'policy_snapshot' => $lockedPreview->policySnapshot,
                'capability_snapshot' => $lockedPreview->capabilitySnapshot,
                'freshness_policy' => $lockedPreview->freshnessPolicy,
                'maximum_cost_micros' => $lockedPreview->maximumCostMicros,
                'currency' => $lockedPreview->currency,
                'input_hash' => $lockedPreview->inputHash,
                'idempotency_key' => $request->idempotencyKey,
                'orchestration_version' => 1,
                'state' => $state,
                'awaiting_reason' => $state === 'awaiting_provider' ? 'provider' : ($state === 'awaiting_budget' ? 'budget' : null),
                'safe_failure_code' => null,
                'current_stage' => 'eligibility',
                'estimated_cost_micros' => $lockedPreview->estimatedCostMicros,
                'reserved_cost_micros' => 0,
                'actual_cost_micros' => 0,
                'budget_envelope_id' => null,
                'research_budget_run_id' => null,
                'qualification_budget_run_id' => null,
                'initiated_by_user_id' => $request->actor?->id,
                'queued_at' => now(),
                'started_at' => null,
                'finished_at' => null,
                'cancelled_at' => null,
            ]);

            $this->writeInputManifest($run, $accountModel, $lockedPreview);
            foreach ($lockedPreview->stages as $stage) {
                AccountResearchStage::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspaceModel->id,
                    'research_run_id' => $run->id,
                    'stage_order' => $stage['stage_order'],
                    'stage_key' => $stage['stage_key'],
                    'state' => 'pending',
                    'entry_predicate_snapshot' => $stage['entry_predicate'],
                    'missing_facts_snapshot' => $stage['missing_facts'],
                    'expected_cost_micros' => $stage['expected_cost_micros'],
                    'maximum_cost_micros' => $stage['maximum_cost_micros'],
                    'implementation_version' => $stage['implementation_version'],
                    'checkpoint_reference' => null,
                    'checkpoint_hash' => null,
                    'lease_owner' => null,
                    'fencing_token' => 0,
                    'lease_expires_at' => null,
                    'safe_error_code' => null,
                    'started_at' => null,
                    'succeeded_at' => null,
                    'failed_at' => null,
                ]);
            }

            AccountResearchDispatch::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceModel->id,
                'research_run_id' => $run->id,
                'dispatch_kind' => 'stage',
                'stage_key' => 'eligibility',
                'state' => 'pending',
                'available_at' => now(),
                'published_at' => null,
                'publish_lease_owner' => null,
                'publish_lease_expires_at' => null,
                'publish_attempts' => 0,
                'safe_error_code' => null,
            ]);

            // The budget graph is part of the immutable orchestration graph,
            // even when monthly admission is temporarily unavailable. A later
            // retry must reserve against this envelope and its task-specific
            // runs; passing a null run ID would create an unbounded standalone
            // AI run and escape the caller's S2 hard maximum.
            $envelope = $this->budget->openEnvelope(
                $workspaceModel,
                $request->actor,
                'account_research',
                (string) $run->id,
                $lockedPreview->maximumCostMicros,
                $lockedPreview->currency,
            );
            $researchBudgetRun = $this->budget->openRun(
                $workspaceModel,
                $request->actor,
                AiTask::AccountResearch,
                $envelope,
            );
            $qualificationBudgetRun = $this->budget->openRun(
                $workspaceModel,
                $request->actor,
                AiTask::Qualification,
                $envelope,
            );
            $run->forceFill([
                'budget_envelope_id' => $envelope->id,
                'research_budget_run_id' => $researchBudgetRun->id,
                'qualification_budget_run_id' => $qualificationBudgetRun->id,
            ])->save();

            return $run->fresh();
        }, 3);
    }

    public function start(StartAccountResearchRequest $request): AccountResearchRun
    {
        return $this->handle($request);
    }

    private function assertIdempotencyKey(string $key): void
    {
        if (trim($key) === '' || strlen($key) > 160) {
            throw ValidationException::withMessages(['idempotency_key' => 'A bounded idempotency key is required.']);
        }
    }

    /** @param list<array{code:string,message:string}> $blockers */
    private function hasHardBlocker(array $blockers): bool
    {
        foreach ($blockers as $blocker) {
            if (! in_array($blocker['code'], ['provider_unavailable', 'budget_unavailable'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{code:string,message:string}> $blockers */
    private function initialState(array $blockers): string
    {
        $codes = array_column($blockers, 'code');
        if (in_array('provider_unavailable', $codes, true)) {
            return 'awaiting_provider';
        }
        if (in_array('budget_unavailable', $codes, true) || in_array('maximum_cost_too_low', $codes, true)) {
            return 'awaiting_budget';
        }

        return 'planned';
    }

    private function assertNoActiveRun(Workspace $workspace, Account $account): void
    {
        $active = AccountResearchRun::query()
            ->where('workspace_id', $workspace->id)
            ->where('account_id', $account->id)
            ->whereIn('state', ['planned', 'awaiting_provider', 'awaiting_budget', 'running', 'paused', 'cancelling'])
            ->lockForUpdate()
            ->first();
        if ($active !== null) {
            throw new AccountResearchBusy('This Account already has an active research run.');
        }
    }

    private function writeInputManifest(
        AccountResearchRun $run,
        Account $account,
        AccountResearchPreflight $preview,
    ): void {
        foreach (['id', 'name', 'website', 'domain_normalized', 'tags', 'source'] as $field) {
            AccountResearchRunInput::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $run->workspace_id,
                'research_run_id' => $run->id,
                'input_type' => 'account_field',
                'source_observation_id' => null,
                'prior_claim_id' => null,
                'source_policy_hash' => $preview->policySnapshot['source_policy_hash'],
                'llm_use_classification' => 'allowed',
                'display_classification' => 'standard',
                'export_classification' => 'standard',
                'observed_at' => null,
                'expires_at' => null,
                'content_hash' => CanonicalIntelligence::valueHash($preview->accountSnapshot[$field] ?? null),
            ]);
        }

        $handles = $preview->policySnapshot['s1_observation_handles'] ?? [];
        if (! is_array($handles)) {
            return;
        }
        foreach ($handles as $handle) {
            if (! is_array($handle) || ! is_string($handle['observation_id'] ?? null)) {
                continue;
            }
            AccountResearchRunInput::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $run->workspace_id,
                'research_run_id' => $run->id,
                'input_type' => 'source_observation',
                'source_observation_id' => $handle['observation_id'],
                'prior_claim_id' => null,
                'source_policy_hash' => (string) ($handle['policy_hash'] ?? $preview->policySnapshot['source_policy_hash']),
                'llm_use_classification' => 'allowed',
                'display_classification' => (string) ($handle['display_classification'] ?? 'standard'),
                'export_classification' => (string) ($handle['export_classification'] ?? 'standard'),
                'observed_at' => $handle['observed_at'] ?? null,
                'expires_at' => $handle['retain_until'] ?? null,
                'content_hash' => CanonicalIntelligence::valueHash($handle),
            ]);
        }
    }
}
