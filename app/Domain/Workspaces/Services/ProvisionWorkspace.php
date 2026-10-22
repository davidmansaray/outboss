<?php

namespace App\Domain\Workspaces\Services;

use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Gtm\Enums\GtmProfileResearchState;
use App\Domain\Gtm\Enums\GtmProfileVersionActor;
use App\Domain\Gtm\Enums\GtmProfileVersionOrigin;
use App\Domain\Gtm\Enums\GtmResearchRunState;
use App\Domain\Gtm\Support\CanonicalGtmProfilePayload;
use App\Domain\Gtm\Support\GtmProfilePayloadValidator;
use App\Domain\Gtm\Support\PublicWebsiteUrl;
use App\Domain\Objectives\Services\EnsureDefaultOutreachPolicy;
use App\Jobs\Gtm\ResearchWorkspaceGtmProfile;
use App\Models\GtmProfile;
use App\Models\GtmProfileResearchDispatch;
use App\Models\GtmProfileResearchRun;
use App\Models\GtmProfileVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceClientOperation;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Create and backfill the records that make a workspace usable.
 *
 * Workspace creation has historically been implemented in several callers.
 * Keeping the profile, version, AI defaults, and optional research dispatch in
 * this service makes those paths idempotent and gives existing workspaces the
 * same invariants as new ones.
 */
final class ProvisionWorkspace
{
    public function __construct(
        private readonly AiWorkspaceSettings $aiSettings,
        private readonly GtmProfilePayloadValidator $payloadValidator,
        private readonly WorkspaceAvatarManager $avatars,
        private readonly EnsureDefaultOutreachPolicy $outreachPolicies,
    ) {}

    /**
     * Create a workspace and all of its baseline records.
     */
    public function handle(
        User $owner,
        string $name,
        string $type = 'team',
        ?string $websiteUrl = null,
        ?string $clientOperationId = null,
    ): Workspace {
        return $this->create($owner, $name, $type, $websiteUrl, $clientOperationId);
    }

    /**
     * Alias used by callers that prefer an explicit create verb.
     */
    public function create(
        User $owner,
        string $name,
        string $type = 'team',
        ?string $websiteUrl = null,
        ?string $clientOperationId = null,
    ): Workspace {
        $name = $this->validatedName($name);
        $type = $this->validatedType($type);
        // Canonicalize cheaply first so a completed idempotent operation can
        // be replayed without depending on DNS still having the same answer.
        // A genuinely new operation is network-validated immediately below.
        $normalizedWebsite = $this->normalizeWebsiteSyntax($websiteUrl);
        $clientOperationId = $this->validatedClientOperationId($clientOperationId);
        $fingerprint = $clientOperationId !== null
            ? $this->workspaceFingerprint($owner, $name, $type, $normalizedWebsite)
            : null;

        if ($clientOperationId !== null && $fingerprint !== null) {
            $replayed = $this->replayCompletedOperation($owner, $clientOperationId, $fingerprint);
            if ($replayed !== null) {
                return $replayed;
            }
        }

        $normalizedWebsite = $this->normalizeWebsite($normalizedWebsite);

        $avatarWebsite = null;
        $workspace = DB::transaction(function () use (
            $owner,
            $name,
            $type,
            $normalizedWebsite,
            $clientOperationId,
            $fingerprint,
            &$avatarWebsite,
        ): Workspace {
            $operation = null;
            if ($clientOperationId !== null) {
                // Claim the stable user/operation key before creating any
                // workspace records. The unique index serializes concurrent
                // retries on MySQL; insertOrIgnore lets the loser inspect and
                // replay the committed result instead of creating a second
                // workspace after a check-then-insert race.
                $claimed = WorkspaceClientOperation::query()->insertOrIgnore([
                    'initiating_user_id' => $owner->id,
                    'client_operation_id' => $clientOperationId,
                    'server_fingerprint' => $fingerprint,
                    'resulting_workspace_id' => null,
                    'redirect_route' => 'gtm-profile.show',
                    'redirect_parameters' => json_encode([], JSON_THROW_ON_ERROR),
                    'response_metadata' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $operation = WorkspaceClientOperation::query()
                    ->where('initiating_user_id', $owner->id)
                    ->where('client_operation_id', $clientOperationId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! hash_equals((string) $operation->server_fingerprint, $fingerprint)) {
                    throw new RuntimeException('operation_replay_mismatch');
                }

                if ($claimed === 0) {
                    if ($operation->resulting_workspace_id === null) {
                        throw new RuntimeException('workspace_operation_in_progress');
                    }
                    $replayed = Workspace::query()->find($operation->resulting_workspace_id);
                    if ($replayed === null) {
                        throw new RuntimeException('workspace_operation_result_missing');
                    }

                    return $replayed;
                }
            }

            $workspace = Workspace::query()->create([
                'name' => $name,
                'type' => $type,
            ]);

            $this->ensureMembership($workspace, $owner);
            $workspace = $this->provisionRecords($workspace, $owner, $normalizedWebsite);
            $avatarWebsite = $normalizedWebsite;

            if ($operation !== null) {
                $operation->forceFill([
                    'resulting_workspace_id' => $workspace->id,
                    'redirect_route' => 'gtm-profile.show',
                    'redirect_parameters' => [],
                    'response_metadata' => [
                        'workspace_id' => $workspace->id,
                    ],
                ])->save();
            }

            return $workspace;
        }, 3);

        if ($avatarWebsite !== null) {
            $this->avatars->dispatchRefresh((int) $workspace->id, $avatarWebsite);
        }

        return $workspace->fresh();
    }

    /**
     * Ensure an already persisted workspace has the same baseline records as a
     * newly-created one. This method deliberately does not overwrite an
     * existing profile version or change an existing member's role.
     */
    public function ensure(
        Workspace $workspace,
        ?User $owner = null,
        ?string $websiteUrl = null,
    ): Workspace {
        $normalizedWebsite = $this->normalizeWebsite($websiteUrl);

        $workspace = DB::transaction(function () use ($workspace, $owner, $normalizedWebsite): Workspace {
            $lockedWorkspace = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            if ($owner !== null) {
                $this->ensureMembership($lockedWorkspace, $owner);
            }

            $this->provisionRecords($lockedWorkspace, $owner, $normalizedWebsite);

            return $lockedWorkspace;
        }, 3);

        return $workspace->fresh();
    }

    /**
     * Alias used by the backfill command and factories.
     */
    public function provision(
        Workspace $workspace,
        ?User $owner = null,
        ?string $websiteUrl = null,
    ): Workspace {
        return $this->ensure($workspace, $owner, $websiteUrl);
    }

    private function provisionRecords(
        Workspace $workspace,
        ?User $owner,
        ?string $websiteUrl,
    ): Workspace {
        $this->aiSettings->ensureDefaults($workspace);
        $this->outreachPolicies->handle($workspace, $owner);

        $profile = GtmProfile::query()
            ->where('workspace_id', $workspace->id)
            ->lockForUpdate()
            ->first();

        if ($profile === null) {
            $profile = GtmProfile::query()->create([
                'workspace_id' => $workspace->id,
                'research_state' => GtmProfileResearchState::NotStarted,
                'awaiting_reason' => null,
                'current_version_id' => null,
                'current_research_run_id' => null,
                'last_researched_at' => null,
                'last_research_failed_at' => null,
                'safe_error_code' => null,
                'last_researched_website_url' => null,
                'lock_version' => 0,
            ]);
        }

        $version = $this->currentVersion($profile);
        if ($version === null) {
            $seedWebsite = $websiteUrl;
            $payload = CanonicalGtmProfilePayload::seed($workspace->name, $seedWebsite);
            $payload = $this->payloadValidator->validate($payload);
            $version = GtmProfileVersion::query()->create([
                'workspace_id' => $workspace->id,
                'gtm_profile_id' => $profile->id,
                'version' => 1,
                'schema_version' => GtmProfileVersion::SCHEMA_VERSION,
                'payload' => $payload,
                'content_hash' => CanonicalGtmProfilePayload::hash($payload),
                'actor_type' => GtmProfileVersionActor::SystemSeed,
                'actor_user_id' => null,
                'actor_display_name' => 'System',
                'origin' => GtmProfileVersionOrigin::WorkspaceSeed,
                'created_at' => now(),
            ]);

            $profile->forceFill([
                'current_version_id' => $version->id,
                'lock_version' => 0,
            ])->save();
        }

        $currentWebsite = $this->websiteFromVersion($version);
        if ($currentWebsite !== null) {
            $this->ensureResearchDispatch($profile, $version, $owner, $currentWebsite);
        }

        return $workspace;
    }

    private function currentVersion(GtmProfile $profile): ?GtmProfileVersion
    {
        if ($profile->current_version_id !== null) {
            $version = GtmProfileVersion::query()
                ->whereKey($profile->current_version_id)
                ->where('gtm_profile_id', $profile->id)
                ->first();
            if ($version !== null) {
                return $version;
            }
        }

        return GtmProfileVersion::query()
            ->where('gtm_profile_id', $profile->id)
            ->orderByDesc('version')
            ->first();
    }

    private function ensureResearchDispatch(
        GtmProfile $profile,
        GtmProfileVersion $version,
        ?User $owner,
        string $websiteUrl,
    ): void {
        $existingRun = null;
        if ($profile->current_research_run_id !== null) {
            $existingRun = GtmProfileResearchRun::query()
                ->whereKey($profile->current_research_run_id)
                ->where('gtm_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();
        }

        if ($existingRun !== null && $existingRun->website_url_snapshot === $websiteUrl) {
            return;
        }

        $sameWebsiteRun = GtmProfileResearchRun::query()
            ->where('gtm_profile_id', $profile->id)
            ->where('website_url_snapshot', $websiteUrl)
            ->whereIn('state', [
                GtmResearchRunState::Queued->value,
                GtmResearchRunState::Running->value,
                GtmResearchRunState::AwaitingProvider->value,
                GtmResearchRunState::AwaitingBudget->value,
            ])
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->first();

        if ($sameWebsiteRun !== null) {
            if ((string) $profile->current_research_run_id !== (string) $sameWebsiteRun->id) {
                $profile->forceFill([
                    'current_research_run_id' => $sameWebsiteRun->id,
                    'research_state' => GtmProfileResearchState::Queued,
                    'awaiting_reason' => null,
                ])->save();
            }

            return;
        }

        $run = GtmProfileResearchRun::query()->create([
            'gtm_profile_id' => $profile->id,
            'state' => GtmResearchRunState::Queued,
            'awaiting_reason' => null,
            'budget_run_id' => null,
            'parent_run_id' => $existingRun?->id,
            'lineage_type' => $existingRun === null ? 'workspace_creation' : 'website_change',
            'website_url_snapshot' => $websiteUrl,
            'base_profile_version_id' => $version->id,
            'input_hash' => hash('sha256', json_encode([
                'website_url' => $websiteUrl,
                'base_profile_version_id' => (string) $version->id,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'initiated_by_user_id' => $owner?->id,
            'safe_failure_code' => null,
            'queued_at' => now(),
        ]);

        GtmProfileResearchDispatch::query()->create([
            'gtm_profile_id' => $profile->id,
            'research_run_id' => $run->id,
            'status' => 'pending',
            'available_at' => now(),
            'published_at' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'publish_attempts' => 0,
            'last_error_code' => null,
        ]);

        $profile->forceFill([
            'current_research_run_id' => $run->id,
            'research_state' => GtmProfileResearchState::Queued,
            'awaiting_reason' => null,
        ])->save();

        $this->registerAfterCommitDispatch($profile, $run);
    }

    private function registerAfterCommitDispatch(
        GtmProfile $profile,
        GtmProfileResearchRun $run,
    ): void {
        $profileId = (string) $profile->id;
        $runId = (string) $run->id;
        $initiatingUserId = $run->initiated_by_user_id !== null ? (int) $run->initiated_by_user_id : null;
        DB::afterCommit(function () use ($profileId, $runId, $initiatingUserId): void {
            dispatch(new ResearchWorkspaceGtmProfile($profileId, $runId, $initiatingUserId));
        });
    }

    private function ensureMembership(Workspace $workspace, User $owner): WorkspaceMembership
    {
        return WorkspaceMembership::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'user_id' => $owner->id,
            ],
            ['role' => WorkspaceMembership::ROLE_OWNER],
        );
    }

    private function normalizeWebsite(?string $websiteUrl): ?string
    {
        try {
            return PublicWebsiteUrl::normalizeAndValidate($websiteUrl);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['website_url' => $exception->getMessage()]);
        }
    }

    private function normalizeWebsiteSyntax(?string $websiteUrl): ?string
    {
        try {
            return PublicWebsiteUrl::normalize($websiteUrl);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['website_url' => $exception->getMessage()]);
        }
    }

    private function replayCompletedOperation(
        User $owner,
        string $clientOperationId,
        string $fingerprint,
    ): ?Workspace {
        $operation = WorkspaceClientOperation::query()
            ->where('initiating_user_id', $owner->id)
            ->where('client_operation_id', $clientOperationId)
            ->first();

        if ($operation === null) {
            return null;
        }

        if (! hash_equals((string) $operation->server_fingerprint, $fingerprint)) {
            throw new RuntimeException('operation_replay_mismatch');
        }

        if ($operation->resulting_workspace_id === null) {
            throw new RuntimeException('workspace_operation_in_progress');
        }

        $workspace = Workspace::query()->find($operation->resulting_workspace_id);
        if ($workspace === null) {
            throw new RuntimeException('workspace_operation_result_missing');
        }

        return $workspace;
    }

    private function validatedName(string $name): string
    {
        $name = trim(str_replace(["\r\n", "\r"], "\n", $name));
        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Enter a workspace name of 120 characters or fewer.']);
        }

        return $name;
    }

    private function validatedType(string $type): string
    {
        $type = trim($type);
        if ($type === '' || mb_strlen($type) > 32) {
            throw ValidationException::withMessages(['type' => 'The workspace type is invalid.']);
        }

        return $type;
    }

    private function validatedClientOperationId(?string $clientOperationId): ?string
    {
        if ($clientOperationId === null) {
            return null;
        }

        $clientOperationId = trim($clientOperationId);
        if ($clientOperationId === '' || strlen($clientOperationId) > 128) {
            throw ValidationException::withMessages([
                'client_operation_id' => 'The client operation ID is invalid.',
            ]);
        }

        return $clientOperationId;
    }

    private function workspaceFingerprint(
        User $owner,
        string $name,
        string $type,
        ?string $websiteUrl,
    ): string {
        return CanonicalGtmProfilePayload::hash([
            'action' => 'workspace.create',
            'initiating_user_id' => (int) $owner->id,
            'name' => $name,
            'type' => $type,
            'website_url' => $websiteUrl,
        ]);
    }

    private function websiteFromVersion(GtmProfileVersion $version): ?string
    {
        $payload = $version->payload;
        $website = data_get($payload, 'company.website_url.value');

        return is_string($website) && $website !== '' ? $website : null;
    }
}
