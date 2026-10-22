<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use App\Domain\Objectives\Enums\ExecutionPlanActionKind;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $workspace_id
 * @property string $execution_plan_id
 * @property string $gtm_experiment_assignment_id
 * @property string $gtm_experiment_arm_id
 * @property string $objective_run_arm_draft_id
 * @property string $strategy_version_id
 * @property string $audience_snapshot_member_id
 * @property int|null $account_id
 * @property int|null $person_id
 * @property string $offer_version_id
 * @property string $conversion_definition_version_id
 * @property list<string> $selected_claim_ids
 * @property list<string> $selected_evidence_ids
 * @property array<string, string> $selected_claim_safety_hashes
 * @property string $offer_content_hash
 * @property string $offer_contract_hash
 * @property string $cta_destination_url
 * @property string $cta_response_route
 * @property ExecutionPlanActionKind $action_kind
 * @property int $position
 * @property string $destination_email_hash
 * @property string|null $destination_email_address
 * @property string|null $subject
 * @property string|null $body_text
 * @property string|null $body_html
 * @property string $template_hash
 * @property string $recipient_snapshot_hash
 * @property string $content_hash
 * @property int $mailbox_id
 * @property int $provider_connection_id
 * @property int|null $provider_connection_version
 * @property string $sender_address_snapshot
 * @property string|null $sender_display_name_snapshot
 * @property string|null $reply_to_snapshot
 * @property Carbon $scheduled_not_before
 * @property Carbon $scheduled_not_after
 * @property string $policy_input_hash
 * @property string $action_fingerprint
 * @property ExecutionPlanActionState $state
 */
#[Fillable([
    'id', 'workspace_id', 'execution_plan_id', 'gtm_experiment_assignment_id',
    'gtm_experiment_arm_id', 'objective_run_arm_draft_id', 'strategy_version_id',
    'audience_snapshot_member_id', 'account_id', 'person_id', 'offer_version_id',
    'conversion_definition_version_id', 'selected_claim_ids', 'selected_evidence_ids',
    'selected_claim_safety_hashes', 'offer_content_hash', 'offer_contract_hash',
    'cta_destination_url', 'cta_response_route',
    'action_kind', 'position', 'destination_email_hash', 'destination_email_address', 'subject',
    'body_text', 'body_html', 'template_hash', 'recipient_snapshot_hash', 'content_hash', 'mailbox_id',
    'provider_connection_id', 'provider_connection_version', 'sender_address_snapshot',
    'sender_display_name_snapshot', 'reply_to_snapshot', 'scheduled_not_before', 'scheduled_not_after',
    'policy_input_hash', 'action_fingerprint', 'state',
])]
#[Hidden([
    'destination_email_address',
    'sender_address_snapshot',
    'sender_display_name_snapshot',
    'reply_to_snapshot',
])]
class ExecutionPlanAction extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'selected_claim_ids' => 'array',
            'selected_evidence_ids' => 'array',
            'selected_claim_safety_hashes' => 'array',
            'action_kind' => ExecutionPlanActionKind::class,
            'position' => 'integer',
            'destination_email_address' => 'encrypted',
            'provider_connection_version' => 'integer',
            'sender_address_snapshot' => 'encrypted',
            'sender_display_name_snapshot' => 'encrypted',
            'reply_to_snapshot' => 'encrypted',
            'scheduled_not_before' => 'datetime',
            'scheduled_not_after' => 'datetime',
            'state' => ExecutionPlanActionState::class,
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ExecutionPlan, $this> */
    public function executionPlan(): BelongsTo
    {
        return $this->belongsTo(ExecutionPlan::class);
    }

    /** @return BelongsTo<GtmExperimentAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(GtmExperimentAssignment::class, 'gtm_experiment_assignment_id');
    }

    /** @return BelongsTo<GtmExperimentArm, $this> */
    public function arm(): BelongsTo
    {
        return $this->belongsTo(GtmExperimentArm::class, 'gtm_experiment_arm_id');
    }

    /** @return BelongsTo<ObjectiveRunArmDraft, $this> */
    public function armDraft(): BelongsTo
    {
        return $this->belongsTo(ObjectiveRunArmDraft::class, 'objective_run_arm_draft_id');
    }

    /** @return BelongsTo<StrategyVersion, $this> */
    public function strategyVersion(): BelongsTo
    {
        return $this->belongsTo(StrategyVersion::class);
    }

    /** @return BelongsTo<AudienceSnapshotMember, $this> */
    public function audienceSnapshotMember(): BelongsTo
    {
        return $this->belongsTo(AudienceSnapshotMember::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<OfferVersion, $this> */
    public function offerVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class);
    }

    /** @return BelongsTo<ConversionDefinitionVersion, $this> */
    public function conversionDefinitionVersion(): BelongsTo
    {
        return $this->belongsTo(ConversionDefinitionVersion::class);
    }

    /** @return BelongsTo<Mailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /** @return BelongsTo<ProviderConnection, $this> */
    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class);
    }

    /** @return HasOne<ExecutionPlanActionCompilation, $this> */
    public function compilation(): HasOne
    {
        return $this->hasOne(ExecutionPlanActionCompilation::class);
    }

    /** @return HasMany<ApprovalGrant, $this> */
    public function approvalGrants(): HasMany
    {
        return $this->hasMany(ApprovalGrant::class);
    }

    /** @return HasMany<PolicyEvaluation, $this> */
    public function policyEvaluations(): HasMany
    {
        return $this->hasMany(PolicyEvaluation::class);
    }

    /** @return HasMany<ExecutionActionEvent, $this> */
    public function executionEvents(): HasMany
    {
        return $this->hasMany(ExecutionActionEvent::class);
    }

    /** @return HasOne<Exposure, $this> */
    public function exposure(): HasOne
    {
        return $this->hasOne(Exposure::class);
    }
}
