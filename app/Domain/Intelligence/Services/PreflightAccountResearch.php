<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Services\AiCostEstimator;
use App\Domain\Gtm\Support\PublicWebsiteUrl;
use App\Domain\Intelligence\DTOs\AccountResearchPreflight;
use App\Domain\Intelligence\Enums\AccountResearchStageKey;
use App\Domain\Intelligence\Support\CanonicalIntelligence;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Domain\Prospecting\DTOs\SourcePolicySnapshot;
use App\Models\Account;
use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetSetting;
use App\Models\AiProviderCredential;
use App\Models\GtmProfile;
use App\Models\GtmProfileVersion;
use App\Models\OfferVersion;
use App\Models\SourceObservation;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Build a signed-input-compatible research plan without provider calls or
 * reservations. StartAccountResearch repeats these checks under a lock.
 */
final class PreflightAccountResearch
{
    public function __construct(
        private readonly AiCostEstimator $costs,
    ) {}

    /**
     * @param  array<string,mixed>  $freshnessPolicy
     * @param  list<string>  $capabilities
     */
    public function handle(
        Workspace $workspace,
        Account|int|string $account,
        ?string $gtmProfileVersionId = null,
        ?string $offerVersionId = null,
        array $freshnessPolicy = [],
        array $capabilities = [],
        ?int $maximumCostMicros = null,
    ): AccountResearchPreflight {
        $accountModel = $this->account($workspace, $account);
        $profileVersion = $this->profileVersion($workspace, $gtmProfileVersionId);
        $offerVersion = $this->offerVersion($workspace, $offerVersionId);
        $accountSnapshot = $this->accountSnapshot($accountModel);
        $policy = $this->policySnapshot($accountModel);
        $freshness = $this->freshnessPolicy($freshnessPolicy);
        $selectedCapabilities = $this->capabilities($capabilities);
        $capabilitySnapshot = $this->capabilitySnapshot($workspace, $selectedCapabilities);
        $stageCosts = $this->stageCosts($workspace, $accountSnapshot, $profileVersion, $offerVersion, $selectedCapabilities);
        $estimated = array_sum($stageCosts);
        $maximum = $maximumCostMicros ?? $this->defaultMaximum($estimated);
        $inputHash = CanonicalIntelligence::hash([
            'account' => $accountSnapshot,
            'account_id' => (int) $accountModel->id,
            'gtm_profile_version' => $profileVersion === null ? null : [
                'id' => (string) $profileVersion->id,
                'content_hash' => (string) $profileVersion->content_hash,
                'payload' => $profileVersion->payload,
            ],
            'offer_version' => $offerVersion === null ? null : [
                'id' => (string) $offerVersion->id,
                'content_hash' => (string) $offerVersion->content_hash,
            ],
            'policy' => $policy,
            'capabilities' => $capabilitySnapshot,
            'freshness' => $freshness,
            'maximum_cost_micros' => $maximum,
            'orchestration_version' => 1,
        ]);

        $blockers = [];
        if ($profileVersion === null) {
            $blockers[] = [
                'code' => 'gtm_profile_unavailable',
                'message' => 'A current GTM Profile Version is required.',
            ];
        }
        if (($accountSnapshot['website'] ?? null) === null) {
            $blockers[] = [
                'code' => 'website_missing',
                'message' => 'The supplied Account needs a public website before research can start.',
            ];
        }
        foreach ($capabilitySnapshot['required'] as $capability => $ready) {
            if ($ready !== true) {
                $blockers[] = [
                    'code' => 'provider_unavailable',
                    'message' => "The {$capability} capability is not ready.",
                ];
            }
        }
        if (! $this->budgetReady($workspace, $estimated)) {
            $blockers[] = [
                'code' => 'budget_unavailable',
                'message' => 'The workspace budget is not ready for the conservative estimate.',
            ];
        }
        if ($maximum < $estimated || $maximum < 1) {
            $blockers[] = [
                'code' => 'maximum_cost_too_low',
                'message' => 'The hard maximum must cover the conservative research estimate.',
            ];
        }

        return new AccountResearchPreflight(
            account: $accountModel,
            inputHash: $inputHash,
            blocked: $blockers !== [],
            blockers: $blockers,
            stages: $this->stages($accountSnapshot, $selectedCapabilities, $stageCosts, $maximum),
            accountSnapshot: $accountSnapshot,
            policySnapshot: $policy,
            capabilitySnapshot: $capabilitySnapshot,
            freshnessPolicy: $freshness,
            estimatedCostMicros: $estimated,
            maximumCostMicros: $maximum,
            currency: (string) (AiBudgetSetting::query()->whereKey($workspace->id)->value('currency') ?: 'USD'),
            gtmProfileVersionId: $profileVersion?->id,
            offerVersionId: $offerVersion?->id,
        );
    }

    /**
     * Alias for callers using an explicit preflight verb.
     *
     * @param  array<string,mixed>  $freshnessPolicy
     * @param  list<string>  $capabilities
     */
    public function preflight(
        Workspace $workspace,
        Account|int|string $account,
        ?string $gtmProfileVersionId = null,
        ?string $offerVersionId = null,
        array $freshnessPolicy = [],
        array $capabilities = [],
        ?int $maximumCostMicros = null,
    ): AccountResearchPreflight {
        return $this->handle($workspace, $account, $gtmProfileVersionId, $offerVersionId, $freshnessPolicy, $capabilities, $maximumCostMicros);
    }

    public function account(Workspace $workspace, Account|int|string $account): Account
    {
        $accountId = $account instanceof Account ? $account->id : $account;

        return Account::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($accountId)
            ->firstOrFail();
    }

    public function profileVersion(Workspace $workspace, ?string $versionId = null): ?GtmProfileVersion
    {
        $profile = GtmProfile::query()->where('workspace_id', $workspace->id)->first();
        if ($profile === null) {
            return null;
        }
        $query = GtmProfileVersion::query()->where('gtm_profile_id', $profile->id);
        if ($versionId !== null) {
            return $query->whereKey($versionId)->first();
        }

        return $query->whereKey($profile->current_version_id)->first()
            ?? $query->orderByDesc('version')->first();
    }

    public function offerVersion(Workspace $workspace, ?string $versionId = null): ?OfferVersion
    {
        if ($versionId === null) {
            return null;
        }

        return OfferVersion::query()
            ->whereKey($versionId)
            ->whereHas('offer', static fn ($query) => $query->where('workspace_id', $workspace->id))
            ->first();
    }

    /** @return array<string,mixed> */
    public function accountSnapshot(Account $account): array
    {
        // Notes are deliberately excluded: Account notes are not an approved
        // public-data input. The snapshot is the exact bounded S1 identity.
        return [
            'id' => (int) $account->id,
            'name' => mb_substr((string) $account->name, 0, 240),
            'website' => $this->normalizedWebsite($account->website),
            'domain_normalized' => $account->domain_normalized,
            'tags' => array_slice(ModelAttribute::array($account->tags), 0, 50),
            'source' => (string) $account->source,
        ];
    }

    /** @return array<string,mixed> */
    public function policySnapshot(Account $account): array
    {
        $observations = $account->sourceObservations()
            ->where('workspace_id', $account->workspace_id)
            ->get(['id', 'policy_hash', 'policy_classification', 'display_classification', 'export_classification', 'provider_observed_at', 'retain_until', 'expired_at', 'redacted_at']);
        $handles = $observations->map(static fn (SourceObservation $observation): array => [
            'observation_id' => (string) $observation->id,
            'policy_hash' => (string) $observation->policy_hash,
            'policy_classification' => (string) $observation->policy_classification,
            'display_classification' => (string) $observation->display_classification,
            'export_classification' => (string) $observation->export_classification,
            'observed_at' => ModelAttribute::instant($observation->provider_observed_at)?->toIso8601String(),
            'retain_until' => ModelAttribute::instant($observation->retain_until)?->toIso8601String(),
            'expired_at' => ModelAttribute::instant($observation->expired_at)?->toIso8601String(),
            'redacted_at' => ModelAttribute::instant($observation->redacted_at)?->toIso8601String(),
        ])->values()->all();

        return [
            'source_policy' => SourcePolicySnapshot::native()->toArray(),
            'source_policy_hash' => SourcePolicySnapshot::native()->hash,
            's1_observation_handles' => $handles,
            'llm_use' => 'public_business_only',
            'display' => 'policy_filtered',
            'export' => 'strictest_source_policy',
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public function freshnessPolicy(array $policy): array
    {
        $maxSnapshotAge = max(1, (int) ($policy['snapshot_max_age_hours'] ?? 168));
        $maxClaimAge = max(1, (int) ($policy['claim_max_age_hours'] ?? 720));

        return [
            'snapshot_max_age_hours' => min($maxSnapshotAge, 8760),
            'claim_max_age_hours' => min($maxClaimAge, 8760),
        ];
    }

    /**
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    public function capabilities(array $capabilities): array
    {
        $allowed = ['research.extract', 'research.search', 'llm.structured_generate'];
        if ($capabilities === []) {
            return $allowed;
        }

        $selected = array_values(array_unique(array_map('strval', $capabilities)));
        $invalid = array_diff($selected, $allowed);
        if ($invalid !== []) {
            throw ValidationException::withMessages(['capabilities' => 'One or more selected capabilities are not approved.']);
        }

        return $selected;
    }

    /**
     * @param  list<string>  $capabilities
     * @return array<string,mixed>
     */
    private function capabilitySnapshot(Workspace $workspace, array $capabilities): array
    {
        $tavilyReady = $this->credentialReady($workspace, AiProviderName::Tavily);
        $openRouterReady = $this->credentialReady($workspace, AiProviderName::OpenRouter);

        return [
            'selected' => $capabilities,
            'required' => [
                'research.extract' => in_array('research.extract', $capabilities, true) ? $tavilyReady : true,
                'research.search' => in_array('research.search', $capabilities, true) ? $tavilyReady : true,
                'llm.structured_generate' => in_array('llm.structured_generate', $capabilities, true) ? $openRouterReady : true,
            ],
            'providers' => [
                'tavily' => ['ready' => $tavilyReady, 'credential_status' => $this->credentialStatus($workspace, AiProviderName::Tavily)],
                'openrouter' => ['ready' => $openRouterReady, 'credential_status' => $this->credentialStatus($workspace, AiProviderName::OpenRouter)],
            ],
            'policy_version' => 1,
        ];
    }

    private function credentialReady(Workspace $workspace, AiProviderName $provider): bool
    {
        return AiProviderCredential::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', $provider->value)
            ->where('status', AiCredentialStatus::Healthy->value)
            ->whereNotNull('active_version_id')
            ->whereHas('activeVersion', static fn ($query) => $query->whereNull('wiped_at')->whereNotNull('encrypted_secret'))
            ->exists();
    }

    private function credentialStatus(Workspace $workspace, AiProviderName $provider): string
    {
        $status = AiProviderCredential::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', $provider->value)
            ->value('status');

        return is_string($status) ? $status : 'not_configured';
    }

    private function budgetReady(Workspace $workspace, int $estimate): bool
    {
        $settings = AiBudgetSetting::query()->whereKey($workspace->id)->first();
        if ($settings === null || (int) $settings->monthly_limit_micros <= 0) {
            return false;
        }
        $period = $this->currentPeriod($workspace);
        if ($period === null) {
            return $estimate <= (int) $settings->monthly_limit_micros;
        }

        return ! $period->over_limit && ! $period->budget_breach
            && ((int) $period->committed_micros + (int) $period->held_micros + $estimate) <= (int) $period->limit_snapshot_micros;
    }

    private function currentPeriod(Workspace $workspace): ?AiBudgetPeriod
    {
        $start = now('UTC')->startOfMonth()->toDateString();

        return AiBudgetPeriod::query()
            ->where('workspace_id', $workspace->id)
            ->whereDate('period_start_utc', $start)
            ->first();
    }

    private function defaultMaximum(int $estimated): int
    {
        return $estimated + 50_000;
    }

    /**
     * @param  array<string,mixed>  $accountSnapshot
     * @param  list<string>  $selectedCapabilities
     * @return array<string,int>
     */
    private function stageCosts(
        Workspace $workspace,
        array $accountSnapshot,
        ?GtmProfileVersion $profileVersion,
        ?OfferVersion $offerVersion,
        array $selectedCapabilities,
    ): array {
        $price = (int) (AiBudgetSetting::query()->whereKey($workspace->id)->value('tavily_credit_price_micros') ?: config('ai.tavily_default_micros_per_credit', 8_000));
        $costs = [
            AccountResearchStageKey::Eligibility->value => 0,
            AccountResearchStageKey::DomainAvailability->value => 0,
            AccountResearchStageKey::Qualification->value => 0,
        ];
        if (in_array('research.extract', $selectedCapabilities, true)) {
            $costs[AccountResearchStageKey::WebsiteRetrieval->value] = $this->costs->tavilyExtract(1, 'basic', $price)->costMicros;
        }
        if (in_array('research.search', $selectedCapabilities, true)) {
            $costs[AccountResearchStageKey::TargetedSearch->value] = $this->costs->tavilySearch('basic', $price)->costMicros;
        }
        if (in_array('llm.structured_generate', $selectedCapabilities, true)) {
            $researchInput = CanonicalIntelligence::encode([
                'account' => $accountSnapshot,
                'profile' => $profileVersion?->payload,
                'offer' => $offerVersion?->payload,
                'source_handles' => [],
            ]);
            $costs[AccountResearchStageKey::ClaimSynthesis->value] = $this->costs->openRouter(
                (string) config('ai.tasks.account_research.model_id', 'openai/gpt-5'),
                strlen($researchInput),
                (int) config('ai.tasks.account_research.maximum_output_tokens', 4000),
            )->costMicros;
            // Qualification receives the accepted Claims only after the
            // research stages run, so preflight cannot know its final input
            // length. Reserve the full task input bound as the conservative
            // OpenRouter estimate; this keeps the shared hard maximum honest
            // even when the projected Claim set is large.
            $costs[AccountResearchStageKey::Qualification->value] = $this->costs->openRouter(
                (string) config('ai.tasks.qualification.model_id', 'openai/gpt-5-mini'),
                (int) config('ai.tasks.qualification.maximum_input_bytes', 80_000),
                (int) config('ai.tasks.qualification.maximum_output_tokens', 2_000),
            )->costMicros;
        }

        return $costs;
    }

    /**
     * @param  array<string,mixed>  $accountSnapshot
     * @param  list<string>  $selectedCapabilities
     * @param  array<string,int>  $stageCosts
     * @return list<array<string,mixed>>
     */
    private function stages(array $accountSnapshot, array $selectedCapabilities, array $stageCosts, int $maximum): array
    {
        $selectedStages = [
            AccountResearchStageKey::Eligibility,
            AccountResearchStageKey::DomainAvailability,
        ];
        if (in_array('research.extract', $selectedCapabilities, true)) {
            $selectedStages[] = AccountResearchStageKey::WebsiteRetrieval;
        }
        if (in_array('research.search', $selectedCapabilities, true)) {
            $selectedStages[] = AccountResearchStageKey::TargetedSearch;
        }
        if (in_array('llm.structured_generate', $selectedCapabilities, true)) {
            $selectedStages[] = AccountResearchStageKey::ClaimSynthesis;
        }
        $selectedStages[] = AccountResearchStageKey::Qualification;

        return array_map(
            static fn (AccountResearchStageKey $stage, int $index): array => [
                'stage_key' => $stage->value,
                'stage_order' => $index + 1,
                'state' => 'pending',
                'entry_predicate' => $stage === AccountResearchStageKey::TargetedSearch
                    ? ['conditional' => true, 'reason' => 'named rubric facts remain unresolved']
                    : ['account_id' => $accountSnapshot['id']],
                'missing_facts' => $stage === AccountResearchStageKey::Qualification ? ['rubric_required_facts'] : [],
                'expected_cost_micros' => $stageCosts[$stage->value] ?? 0,
                'maximum_cost_micros' => $maximum,
                'implementation_version' => 1,
            ],
            $selectedStages,
            array_keys($selectedStages),
        );
    }

    private function normalizedWebsite(?string $website): ?string
    {
        try {
            return PublicWebsiteUrl::normalize($website);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
