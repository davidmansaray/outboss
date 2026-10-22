<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ExecutionPlanActionKind;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseName;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\ExecutionPlanFingerprint;
use App\Models\Campaign;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\ObjectiveRun;
use App\Models\Person;
use App\Models\SuppressionEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Internal preparation only. Exact validation and approval are separate boundaries. */
final class BuildHumanExecutionPlan
{
    public const VERSION = 'human_execution_plan_v2';

    public const LEGACY_VERSION = 'human_execution_plan_v1';

    public function handle(Campaign $campaign, ObjectiveRun $run, User $actor, bool $existingOnly = false, ?Workspace $workspace = null): ExecutionPlan
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Human plan creation requires the enclosing preparation transaction.');
        }
        // This locks the campaign/run and verifies owner, context, copy and evidence.
        $draft = app(FreezeHumanCampaignDraft::class)->handle($campaign, $run, $actor, $existingOnly, $workspace);
        $run = $draft->objectiveRun()->firstOrFail();
        $context = $run->objectiveVersion()->firstOrFail();
        $campaign = Campaign::query()->where('workspace_id', $run->workspace_id)->whereKey($campaign->id)->firstOrFail();
        $policyId = $context->authority_envelope['outreach_policy_version_id'] ?? null;
        if (! is_string($policyId)) {
            throw ValidationException::withMessages(['policy' => 'The human context has no frozen policy.']);
        }
        $existing = $run->executionPlans()->where('workspace_id', $run->workspace_id)->lockForUpdate()->first();
        $version = $existing->schema_version ?? self::VERSION;
        if (! in_array($version, [self::VERSION, self::LEGACY_VERSION], true)) {
            throw ValidationException::withMessages(['plan' => 'This human approval version is unsupported.']);
        }
        $planId = $existing->id ?? (string) Str::uuid();
        $existingActions = $existing?->actions()->get()->keyBy('audience_snapshot_member_id');
        $members = $context->audienceSnapshot->members()->where('workspace_id', $run->workspace_id)->get()->keyBy('id');
        $recipients = $campaign->recipients()->where('workspace_id', $run->workspace_id)->humanReviewAudience()->lockForUpdate()->get()->keyBy('id');
        $actions = [];
        $offer = $draft->cta_manifest;
        foreach ($draft->claim_manifest['members'] as $message) {
            $recipient = $recipients->get($message['recipient_id']);
            $member = $members->get($message['snapshot_member_id']);
            if ($recipient === null || $member === null || (int) $recipient->sequence_version_id !== (int) $draft->claim_manifest['sequence_version_id']) {
                throw ValidationException::withMessages(['audience' => 'A frozen recipient or sequence changed.']);
            }
            $mailbox = $campaign->mailboxes()->where('mailboxes.workspace_id', $run->workspace_id)
                ->where('mailboxes.id', $recipient->mailbox_id)->where('mailboxes.status', 'connected')
                ->whereNull('mailboxes.sending_paused_at')->lockForUpdate()->first();
            $connection = $mailbox?->connection()->where('workspace_id', $run->workspace_id)->where('status', 'connected')->lockForUpdate()->first();
            if ($mailbox === null || $connection === null) {
                throw ValidationException::withMessages(['mailbox' => 'Each recipient needs its selected, connected campaign mailbox.']);
            }
            $sender = app(MailboxSenderIdentityService::class)->effective($mailbox);
            $email = EmailNormalizer::normalize($recipient->email_address);
            if (! EmailNormalizer::isValid($email) || $email !== $recipient->email_normalized) {
                throw ValidationException::withMessages(['audience' => 'The exact recipient email is invalid.']);
            }
            $person = Person::query()->where('workspace_id', $run->workspace_id)->whereKey($recipient->person_id)
                ->where('account_id', $recipient->account_id)->whereHas('account', fn ($query) => $query->where('workspace_id', $run->workspace_id))
                ->lockForUpdate()->first();
            if ($person === null || EmailNormalizer::normalize($person->email_address) !== $email) {
                throw ValidationException::withMessages(['audience' => 'The selected prospect identity changed.']);
            }
            if (SuppressionEntry::query()->where('workspace_id', $run->workspace_id)->where('email_normalized', $email)->exists()) {
                throw ValidationException::withMessages(['audience' => 'A selected recipient is suppressed.']);
            }
            $claimHashes = [];
            $evidenceIds = [];
            foreach ($message['evidence'] as $claim) {
                $claimHashes[$claim['claim_id']] = $claim['safety_hash'];
                foreach ($claim['supporting_evidence'] as $edge) {
                    $evidenceIds[] = $edge['evidence_id'];
                }
            }
            ksort($claimHashes, SORT_STRING);
            $evidenceIds = array_values(array_unique($evidenceIds));
            sort($evidenceIds, SORT_STRING);
            $recipientHash = CanonicalObjectivePayload::hash([
                'person_id' => $recipient->person_id, 'account_id' => $recipient->account_id,
                'member_hash' => $member->member_hash, 'destination_email_hash' => hash('sha256', $email),
                ...$message['personalization'],
            ]);
            $action = [
                'id' => $existingActions?->get($member->id)->id ?? (string) Str::uuid(),
                'workspace_id' => (int) $run->workspace_id, 'execution_plan_id' => $planId,
                'gtm_experiment_assignment_id' => null, 'gtm_experiment_arm_id' => null, 'strategy_version_id' => null,
                'objective_run_arm_draft_id' => $draft->id, 'audience_snapshot_member_id' => $member->id,
                'account_id' => $recipient->account_id, 'person_id' => $recipient->person_id,
                'offer_version_id' => $context->offer_version_id, 'conversion_definition_version_id' => $context->conversion_definition_version_id,
                'selected_claim_ids' => array_keys($claimHashes), 'selected_evidence_ids' => $evidenceIds, 'selected_claim_safety_hashes' => $claimHashes,
                'offer_content_hash' => $offer['content_hash'], 'offer_contract_hash' => $offer['contract_hash'],
                'cta_destination_url' => $offer['destination_url'], 'cta_response_route' => $offer['response_route'],
                'action_kind' => ExecutionPlanActionKind::EmailNewThread, 'position' => 1,
                'destination_email_hash' => hash('sha256', $email), 'destination_email_address' => $email,
                'subject' => $message['rendered']['subject'], 'body_text' => $message['rendered']['body'], 'body_html' => null,
                'template_hash' => $message['rendered']['template_hash'], 'recipient_snapshot_hash' => $recipientHash,
                'content_hash' => $message['rendered']['content_hash'], 'mailbox_id' => $mailbox->id,
                'provider_connection_id' => $connection->id, 'provider_connection_version' => (int) $connection->authorization_version,
                'sender_address_snapshot' => $sender['email'], 'sender_display_name_snapshot' => $sender['name'], 'reply_to_snapshot' => $sender['email'],
                'scheduled_not_before' => $run->created_at->max($context->starts_at), 'scheduled_not_after' => $context->ends_at,
                'policy_input_hash' => CanonicalObjectivePayload::hash([
                    'version' => $version, 'policy_version_id' => $policyId, 'context_hash' => $context->content_hash,
                    'draft_input_hash' => $draft->input_hash, 'recipient_snapshot_hash' => $recipientHash,
                    'mailbox_id' => $mailbox->id, 'provider_connection_id' => $connection->id,
                    'provider_connection_version' => (int) $connection->authorization_version,
                    'provenance_hash' => $message['rendered']['provenance_hash'],
                ]),
                'state' => ExecutionPlanActionState::AwaitingApproval,
            ];
            $action['action_fingerprint'] = ExecutionPlanFingerprint::action($action);
            $actions[] = $action;
        }
        $fingerprints = array_column($actions, 'action_fingerprint');
        sort($fingerprints, SORT_STRING);
        $dailySendLimit = $campaign->getAttribute('daily_send_limit');
        $manifest = [
            'version' => $version, 'workspace_id' => (int) $run->workspace_id, 'run_id' => $run->id,
            'run_input_hash' => $run->input_hash, 'campaign_id' => $campaign->id,
            'campaign_composition_id' => $run->getAttribute('campaign_composition_id'),
            ...($dailySendLimit === null ? [] : ['daily_send_limit' => (int) $dailySendLimit]),
            'objective_version_id' => $context->id, 'context_hash' => $context->content_hash,
            'audience_snapshot_id' => $context->audience_snapshot_id, 'outreach_policy_version_id' => $policyId,
            'human_draft_id' => $draft->id, 'human_draft_input_hash' => $draft->input_hash,
            'action_fingerprints' => $fingerprints, 'omitted' => [],
        ];
        $canonicalHash = CanonicalObjectivePayload::hash($manifest);
        $planHash = ExecutionPlanFingerprint::plan($planId, $canonicalHash, $actions);
        if ($existing !== null) {
            if ($existing->getAttribute('origin') !== ExecutionOrigin::Human || ! hash_equals($existing->plan_hash, $planHash)
                || $existing->schema_version !== $version || $existing->validator_version !== $version
                || ! hash_equals($existing->canonical_payload_hash, $canonicalHash)
                || ! hash_equals(CanonicalObjectivePayload::hash($existing->input_manifest), $canonicalHash)
                || (int) $existing->action_count !== count($actions)
                || $existingActions->count() !== count($actions)) {
                throw ValidationException::withMessages(['plan' => 'The frozen human plan changed. Prepare a new revision.']);
            }
            foreach ($actions as $action) {
                $stored = $existingActions->get($action['audience_snapshot_member_id']);
                $payload = [];
                foreach (ExecutionPlanFingerprint::ACTION_FIELDS as $field) {
                    $payload[$field] = $stored->getAttribute($field);
                }
                if (! hash_equals($action['action_fingerprint'], ExecutionPlanFingerprint::action($payload))
                    || ! hash_equals($action['action_fingerprint'], $stored->action_fingerprint)) {
                    throw ValidationException::withMessages(['plan' => 'The persisted action differs from the frozen human plan.']);
                }
            }

            return $existing->load('actions');
        }
        if ($existingOnly) {
            throw ValidationException::withMessages(['plan' => 'The saved human plan is missing. Prepare a new review.']);
        }
        $plan = ExecutionPlan::query()->create([
            'id' => $planId, 'workspace_id' => $run->workspace_id, 'objective_run_id' => $run->id, 'origin' => ExecutionOrigin::Human,
            'version' => 1, 'status' => ExecutionPlanStatus::AwaitingApproval,
            'objective_version_id' => $context->id, 'audience_snapshot_id' => $context->audience_snapshot_id, 'gtm_experiment_id' => null,
            'conversion_definition_version_id' => $context->conversion_definition_version_id, 'offer_version_id' => $context->offer_version_id,
            'outreach_policy_version_id' => $policyId, 'model_id' => null, 'ai_task_version' => null, 'prompt_version' => null,
            'schema_version' => self::VERSION, 'validator_version' => self::VERSION, 'arm_count' => 0,
            'account_count' => collect($actions)->pluck('account_id')->unique()->count(), 'people_count' => count($actions), 'action_count' => count($actions),
            'maximum_cost_micros' => 0, 'estimated_cost_micros' => 0, 'actual_cost_micros' => 0,
            'input_manifest' => $manifest, 'evidence_manifest_hash' => CanonicalObjectivePayload::hash(
                collect($actions)->map(fn (array $action): array => [
                    'action_id' => $action['id'], 'claim_ids' => $action['selected_claim_ids'], 'evidence_ids' => $action['selected_evidence_ids'],
                    'claim_safety_hashes' => $action['selected_claim_safety_hashes'], 'offer_contract_hash' => $action['offer_contract_hash'],
                ])->sortBy('action_id')->values()->all(),
            ),
            'compilation_manifest' => ['version' => 'execution_plan_compilation_v1', 'campaigns' => []],
            'canonical_payload_hash' => $canonicalHash, 'plan_hash' => $planHash, 'created_by_user_id' => $actor->id,
        ]);
        foreach ($actions as $candidate) {
            app(RecordExecutionActionEvent::class)->handle(ExecutionPlanAction::query()->create($candidate), ExecutionActionEventType::Planned);
        }
        $run->forceFill(['state' => ObjectiveRunState::NeedsReview, 'current_phase' => ObjectiveRunPhaseName::AwaitApproval])->save();

        return $plan->load('actions');
    }
}
