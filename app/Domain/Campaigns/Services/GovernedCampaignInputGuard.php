<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Exceptions\GovernedCampaignLaunchRequired;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ExecutionPlanCompilationState;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\CampaignRecipient;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\ExecutionPlanActionCompilation;
use App\Models\ExecutionPlanCampaign;
use App\Models\ExecutionPlanCompilation;
use App\Models\ObjectiveRun;
use App\Models\RecipientImport;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Resolve successful compiler lineage and freeze every executable Campaign input. */
final class GovernedCampaignInputGuard
{
    private static ?GovernedCampaignCompilationCapability $compilerCapability = null;

    /**
     * @var array{kind:string,workspace_id:int,campaign_id:int,recipient_id:?int,campaign_attributes:list<string>,recipient_attributes:list<string>,sequence_attributes:list<string>}|null
     */
    private static ?array $runtimeMutation = null;

    /**
     * @template T
     *
     * @param  callable(GovernedCampaignCompilationCapability): T  $callback
     * @return T
     */
    public function runCompilerMutation(ExecutionPlanCompilation $compilation, callable $callback): mixed
    {
        if (DB::transactionLevel() < 1
            || self::$compilerCapability !== null
            || (int) $compilation->getAttribute('workspace_id') < 1
            || (string) $compilation->getAttribute('id') === ''
            || ! $this->isCompilationState($compilation, ExecutionPlanCompilationState::Started)) {
            throw new LogicException('Governed Campaign compilation requires one active started transaction.');
        }

        $capability = new GovernedCampaignCompilationCapability(
            (int) $compilation->getAttribute('workspace_id'),
            (string) $compilation->getAttribute('id'),
            DB::transactionLevel(),
        );
        self::$compilerCapability = $capability;

        try {
            return $callback($capability);
        } finally {
            $capability->revoke();
            self::$compilerCapability = null;
        }
    }

    public function assertCampaignMutable(Campaign $campaign): void
    {
        if ($this->compilerAllows($campaign)) {
            return;
        }

        if ($this->governedCampaignMapping($campaign) === null) {
            return;
        }

        $this->throwFrozen();
    }

    public function assertCompilerCapability(
        GovernedCampaignCompilationCapability $capability,
        int $workspaceId,
    ): void {
        if (self::$compilerCapability !== $capability
            || ! $capability->authorizes($workspaceId, DB::transactionLevel())) {
            throw new LogicException('The governed Campaign compiler capability is not active for this workspace.');
        }
    }

    public function assertCompilerCampaign(
        GovernedCampaignCompilationCapability $capability,
        Campaign $campaign,
    ): void {
        $this->assertCompilerCapability($capability, (int) $campaign->getAttribute('workspace_id'));
        if (! $capability->owns($campaign, DB::transactionLevel())) {
            throw new LogicException('The governed Campaign compiler capability does not own this Campaign.');
        }
    }

    public function assertSequenceMutable(Campaign|SequenceVersion|SequenceStep $subject): void
    {
        $campaign = $subject instanceof Campaign ? $subject : $this->campaignForSequence($subject);
        if ($subject instanceof SequenceVersion
            && $this->runtimeAllowsSequence($campaign, array_keys($subject->getDirty()))) {
            return;
        }
        $this->assertCampaignMutable($campaign);
    }

    public function assertCampaignMailboxMutable(Campaign|int $campaign): void
    {
        $resolved = $campaign instanceof Campaign ? $campaign : Campaign::query()->whereKey($campaign)->first();
        if (! $resolved instanceof Campaign) {
            $this->throwFrozen();
        }

        $this->assertCampaignMutable($resolved);
    }

    public function assertRecipientMutable(CampaignRecipient $recipient): void
    {
        $campaign = $this->campaignForRecipient($recipient);
        if ($this->compilerAllows($campaign)) {
            return;
        }

        $mapping = $this->governedCampaignMapping($campaign);
        if ($mapping === null) {
            return;
        }

        $this->assertRecipientLineage($recipient, $mapping);
        $this->throwFrozen();
    }

    public function assertRecipientImportMutable(RecipientImport $import): void
    {
        $campaignId = $import->getAttribute('campaign_id');
        $campaign = is_numeric($campaignId) ? Campaign::query()->whereKey((int) $campaignId)->first() : null;
        if (! $campaign instanceof Campaign) {
            $this->throwFrozen();
        }

        $this->assertCampaignMutable($campaign);
    }

    public function assertNotGoverned(Campaign $campaign): void
    {
        if ($this->governedCampaignMapping($campaign) !== null) {
            $this->throwFrozen();
        }
    }

    public function assertGenericLaunchAllowed(Campaign $campaign): void
    {
        if (CampaignComposition::query()->where('campaign_id', $campaign->id)->exists()
            || $this->governedCampaignMapping($campaign) !== null) {
            throw new GovernedCampaignLaunchRequired;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runCampaignPause(Campaign $campaign, callable $callback): mixed
    {
        return $this->runRuntimeMutation(
            'campaign_pause',
            $campaign,
            null,
            ['status', 'paused_at'],
            ['remaining_delay_seconds', 'blocked_from_status'],
            $callback,
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runCampaignComplete(Campaign $campaign, callable $callback): mixed
    {
        return $this->runRuntimeMutation(
            'campaign_complete',
            $campaign,
            null,
            ['status', 'completed_at', 'paused_at'],
            ['remaining_delay_seconds', 'blocked_from_status'],
            $callback,
        );
    }

    /**
     * Allow only the publish/activation fields used by the atomic Objective launch.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runCampaignLaunch(Campaign $campaign, callable $callback): mixed
    {
        return $this->runRuntimeMutation(
            'objective_launch',
            $campaign,
            null,
            ['status', 'published_sequence_version_id', 'launched_at', 'paused_at', 'completed_at'],
            ['status', 'sequence_version_id', 'next_sequence_step_id', 'next_send_at', 'remaining_delay_seconds'],
            $callback,
            ['status', 'published_at'],
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runRecipientPause(CampaignRecipient $recipient, callable $callback): mixed
    {
        return $this->runRecipientRuntimeMutation(
            'recipient_pause',
            $recipient,
            ['status', 'paused_at', 'remaining_delay_seconds', 'blocked_from_status'],
            $callback,
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runRecipientResume(CampaignRecipient $recipient, callable $callback): mixed
    {
        return $this->runRecipientRuntimeMutation(
            'recipient_resume',
            $recipient,
            ['status', 'paused_at', 'remaining_delay_seconds', 'blocked_from_status', 'next_send_at'],
            $callback,
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runRecipientStop(CampaignRecipient $recipient, callable $callback): mixed
    {
        return $this->runRecipientRuntimeMutation(
            'recipient_stop',
            $recipient,
            ['status', 'replied_at', 'stopped_at', 'stop_reason', 'next_send_at'],
            $callback,
        );
    }

    /**
     * Narrow execution-state capability for scheduler, lease and finalizer services.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runRecipientExecutionState(CampaignRecipient $recipient, callable $callback): mixed
    {
        return $this->runRecipientRuntimeMutation(
            'recipient_execution_state',
            $recipient,
            [
                'email_domain',
                'status',
                'next_sequence_step_id',
                'next_send_at',
                'first_sent_at',
                'last_sent_at',
                'blocked_at',
                'blocked_from_status',
                'remaining_delay_seconds',
                'send_lease_token',
                'send_lease_expires_at',
                'stop_reason',
                'lock_version',
            ],
            $callback,
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runCampaignSendFinalization(
        Campaign $campaign,
        CampaignRecipient $recipient,
        callable $callback,
    ): mixed {
        return $this->runRuntimeMutation(
            'campaign_send_finalization',
            $campaign,
            $recipient,
            ['status', 'completed_at'],
            [
                'status', 'next_sequence_step_id', 'next_send_at', 'first_sent_at', 'last_sent_at',
                'send_lease_token', 'send_lease_expires_at',
            ],
            $callback,
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runRecipientCanonicalDeletion(CampaignRecipient $recipient, callable $callback): mixed
    {
        return $this->runRecipientRuntimeMutation(
            'recipient_canonical_deletion',
            $recipient,
            [
                'person_id',
                'account_id',
                'email_address',
                'email_normalized',
                'email_domain',
                'full_name',
                'first_name',
                'company',
                'status',
                'next_send_at',
                'stopped_at',
                'stop_reason',
                'removed_at',
                'governed_subject_snapshot',
                'governed_body_text_snapshot',
            ],
            $callback,
        );
    }

    /** @param list<string> $dirty */
    public function runtimeAllowsCampaign(Campaign $campaign, array $dirty): bool
    {
        $scope = self::$runtimeMutation;

        return $scope !== null
            && $scope['workspace_id'] === (int) $campaign->getAttribute('workspace_id')
            && $scope['campaign_id'] === (int) $campaign->getKey()
            && $this->onlyAllowed($dirty, $scope['campaign_attributes']);
    }

    /** @param list<string> $dirty */
    public function runtimeAllowsRecipient(CampaignRecipient $recipient, array $dirty): bool
    {
        $scope = self::$runtimeMutation;

        return $scope !== null
            && $scope['workspace_id'] === (int) $recipient->getAttribute('workspace_id')
            && $scope['campaign_id'] === (int) $recipient->getAttribute('campaign_id')
            && ($scope['recipient_id'] === null || $scope['recipient_id'] === (int) $recipient->getKey())
            && $this->onlyAllowed($dirty, $scope['recipient_attributes']);
    }

    /** @param list<string> $dirty */
    private function runtimeAllowsSequence(Campaign $campaign, array $dirty): bool
    {
        $scope = self::$runtimeMutation;

        return $scope !== null
            && $scope['workspace_id'] === (int) $campaign->getAttribute('workspace_id')
            && $scope['campaign_id'] === (int) $campaign->getKey()
            && $this->onlyAllowed($dirty, $scope['sequence_attributes']);
    }

    /**
     * @template T
     *
     * @param  list<string>  $recipientAttributes
     * @param  callable(): T  $callback
     * @return T
     */
    private function runRecipientRuntimeMutation(
        string $kind,
        CampaignRecipient $recipient,
        array $recipientAttributes,
        callable $callback,
    ): mixed {
        $campaign = $this->campaignForRecipient($recipient);

        return $this->runRuntimeMutation($kind, $campaign, $recipient, [], $recipientAttributes, $callback);
    }

    /**
     * @template T
     *
     * @param  list<string>  $campaignAttributes
     * @param  list<string>  $recipientAttributes
     * @param  callable(): T  $callback
     * @param  list<string>  $sequenceAttributes
     * @return T
     */
    private function runRuntimeMutation(
        string $kind,
        Campaign $campaign,
        ?CampaignRecipient $recipient,
        array $campaignAttributes,
        array $recipientAttributes,
        callable $callback,
        array $sequenceAttributes = [],
    ): mixed {
        if (self::$runtimeMutation !== null) {
            throw new LogicException('Governed Campaign runtime mutation scopes cannot be nested.');
        }

        // Resolve and validate the complete graph before granting the narrow scope.
        $mapping = $this->governedCampaignMapping($campaign);
        if ($mapping === null) {
            return $callback();
        }
        if ($recipient instanceof CampaignRecipient) {
            $this->assertRecipientLineage($recipient, $mapping);
        }

        self::$runtimeMutation = [
            'kind' => $kind,
            'workspace_id' => (int) $campaign->getAttribute('workspace_id'),
            'campaign_id' => (int) $campaign->getKey(),
            'recipient_id' => $recipient?->getKey() !== null ? (int) $recipient->getKey() : null,
            'campaign_attributes' => $campaignAttributes,
            'recipient_attributes' => $recipientAttributes,
            'sequence_attributes' => $sequenceAttributes,
        ];

        try {
            return $callback();
        } finally {
            self::$runtimeMutation = null;
        }
    }

    private function compilerAllows(Campaign $campaign): bool
    {
        $capability = self::$compilerCapability;

        return $capability !== null
            && $capability->allows(
                (int) $campaign->getAttribute('workspace_id'),
                (int) $campaign->getKey(),
                DB::transactionLevel(),
            );
    }

    private function governedCampaignMapping(Campaign $campaign): ?ExecutionPlanCampaign
    {
        $campaignId = $campaign->getKey();
        if (! is_numeric($campaignId) || (int) $campaign->getAttribute('workspace_id') < 1) {
            return null;
        }

        $campaignMappings = ExecutionPlanCampaign::query()->where('campaign_id', (int) $campaignId)->get();
        $actionIndicators = ExecutionPlanActionCompilation::query()->where('campaign_id', (int) $campaignId)->count();
        if ($campaignMappings->isEmpty() && $actionIndicators === 0) {
            return null;
        }
        if ($campaignMappings->count() !== 1) {
            $this->throwFrozen();
        }

        /** @var ExecutionPlanCampaign $mapping */
        $mapping = $campaignMappings->first();
        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        $compilation = ExecutionPlanCompilation::query()->whereKey($mapping->execution_plan_compilation_id)->first();
        $plan = ExecutionPlan::query()->whereKey($mapping->execution_plan_id)->first();
        $run = $plan instanceof ExecutionPlan
            ? ObjectiveRun::query()->whereKey($plan->objective_run_id)->first()
            : null;
        $version = SequenceVersion::query()->whereKey($mapping->sequence_version_id)->first();
        $step = SequenceStep::query()->whereKey($mapping->sequence_step_id)->first();
        if ((int) $mapping->workspace_id !== $workspaceId
            || (int) $mapping->campaign_id !== (int) $campaignId
            || ! $compilation instanceof ExecutionPlanCompilation
            || (int) $compilation->workspace_id !== $workspaceId
            || ! hash_equals((string) $compilation->id, (string) $mapping->execution_plan_compilation_id)
            || ! hash_equals((string) $compilation->execution_plan_id, (string) $mapping->execution_plan_id)
            || ! $this->isCompilationState($compilation, ExecutionPlanCompilationState::Succeeded)
            || ! hash_equals((string) $compilation->succeeded_plan_id, (string) $mapping->execution_plan_id)
            || ! $plan instanceof ExecutionPlan
            || (int) $plan->workspace_id !== $workspaceId
            || ! hash_equals((string) $plan->id, (string) $mapping->execution_plan_id)
            || ! $run instanceof ObjectiveRun
            || (int) $run->workspace_id !== $workspaceId
            || ! hash_equals((string) $run->id, (string) $plan->objective_run_id)
            || ! $version instanceof SequenceVersion
            || (int) $version->campaign_id !== (int) $campaignId
            || (int) $campaign->getAttribute('draft_sequence_version_id') !== (int) $version->id
            || ! $step instanceof SequenceStep
            || (int) $step->sequence_version_id !== (int) $version->id
            || (int) $mapping->sequence_step_id !== (int) $step->id) {
            $this->throwFrozen();
        }

        $actionLinks = ExecutionPlanActionCompilation::query()
            ->where('campaign_id', (int) $campaignId)
            ->get();
        $recipientCount = CampaignRecipient::query()
            ->where('campaign_id', (int) $campaignId)
            ->when($plan->getAttribute('origin') === ExecutionOrigin::Human, fn ($query) => $query->humanReviewAudience())
            ->count();
        if ($actionLinks->isEmpty() || $actionLinks->count() !== $recipientCount) {
            $this->throwFrozen();
        }
        foreach ($actionLinks as $actionLink) {
            $this->assertActionLineage($actionLink, $mapping);
        }

        return $mapping;
    }

    private function assertRecipientLineage(CampaignRecipient $recipient, ExecutionPlanCampaign $mapping): void
    {
        $links = ExecutionPlanActionCompilation::query()
            ->where('campaign_recipient_id', $recipient->getKey())
            ->get();
        if ($links->count() !== 1) {
            $this->throwFrozen();
        }

        /** @var ExecutionPlanActionCompilation $link */
        $link = $links->first();
        if ((int) $recipient->getAttribute('workspace_id') !== (int) $mapping->workspace_id
            || (int) $recipient->getAttribute('campaign_id') !== (int) $mapping->campaign_id
            || (int) $link->campaign_recipient_id !== (int) $recipient->getKey()) {
            $this->throwFrozen();
        }
        $this->assertActionLineage($link, $mapping);
    }

    private function assertActionLineage(
        ExecutionPlanActionCompilation $link,
        ExecutionPlanCampaign $mapping,
    ): void {
        $action = ExecutionPlanAction::query()->whereKey($link->execution_plan_action_id)->first();
        $recipient = CampaignRecipient::query()->whereKey($link->campaign_recipient_id)->first();
        if ((int) $link->workspace_id !== (int) $mapping->workspace_id
            || ! hash_equals((string) $link->execution_plan_compilation_id, (string) $mapping->execution_plan_compilation_id)
            || ! hash_equals((string) $link->execution_plan_id, (string) $mapping->execution_plan_id)
            || ! hash_equals((string) $link->execution_plan_campaign_id, (string) $mapping->id)
            || (int) $link->campaign_id !== (int) $mapping->campaign_id
            || (int) $link->sequence_step_id !== (int) $mapping->sequence_step_id
            || ! $action instanceof ExecutionPlanAction
            || (int) $action->workspace_id !== (int) $mapping->workspace_id
            || ! hash_equals((string) $action->execution_plan_id, (string) $mapping->execution_plan_id)
            || ! hash_equals((string) $link->compiled_input_hash, (string) $action->action_fingerprint)
            || ! hash_equals((string) $link->rendered_content_hash, (string) $action->content_hash)
            || ! $recipient instanceof CampaignRecipient
            || (int) $recipient->getAttribute('workspace_id') !== (int) $mapping->workspace_id
            || (int) $recipient->campaign_id !== (int) $mapping->campaign_id
            || (int) $recipient->sequence_version_id !== (int) $mapping->sequence_version_id
            || (! $this->isCurrentCompiledStep($recipient, $mapping)
                && ! $this->hasSafelyRetiredCompiledStep($recipient))) {
            $this->throwFrozen();
        }
    }

    private function isCurrentCompiledStep(CampaignRecipient $recipient, ExecutionPlanCampaign $mapping): bool
    {
        return is_numeric($recipient->next_sequence_step_id)
            && (int) $recipient->next_sequence_step_id === (int) $mapping->sequence_step_id;
    }

    private function hasSafelyRetiredCompiledStep(CampaignRecipient $recipient): bool
    {
        $status = $recipient->getAttribute('status');
        $status = $status instanceof RecipientStatus
            ? $status
            : RecipientStatus::tryFrom((string) $status);

        return $recipient->next_sequence_step_id === null
            && $status?->isCompletable() === true
            && ($recipient->first_sent_at !== null || $recipient->stopped_at !== null);
    }

    private function campaignForSequence(SequenceVersion|SequenceStep $subject): Campaign
    {
        $version = $subject instanceof SequenceVersion
            ? $subject
            : SequenceVersion::query()->whereKey($subject->getAttribute('sequence_version_id'))->first();
        $campaign = $version instanceof SequenceVersion
            ? Campaign::query()->whereKey($version->getAttribute('campaign_id'))->first()
            : null;
        if (! $campaign instanceof Campaign) {
            $this->throwFrozen();
        }

        return $campaign;
    }

    private function campaignForRecipient(CampaignRecipient $recipient): Campaign
    {
        $campaignId = $recipient->getAttribute('campaign_id');
        $campaign = is_numeric($campaignId) ? Campaign::query()->whereKey((int) $campaignId)->first() : null;
        if (! $campaign instanceof Campaign) {
            $this->throwFrozen();
        }

        return $campaign;
    }

    /**
     * @param  list<string>  $dirty
     * @param  list<string>  $allowed
     */
    private function onlyAllowed(array $dirty, array $allowed): bool
    {
        return $dirty !== [] && array_diff($dirty, $allowed) === [];
    }

    private function isCompilationState(
        ExecutionPlanCompilation $compilation,
        ExecutionPlanCompilationState $expected,
    ): bool {
        $state = $compilation->getAttribute('state');

        return $state === $expected || $state === $expected->value;
    }

    private function throwFrozen(): never
    {
        throw new GovernedCampaignInputMutation;
    }
}
