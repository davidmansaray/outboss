<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\HasExecutionOrigin;
use App\Domain\Objectives\Concerns\ImmutableRecord;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $workspace_id
 * @property string $objective_run_id
 * @property int $version
 * @property ExecutionPlanStatus $status
 * @property string $objective_version_id
 * @property string $audience_snapshot_id
 * @property string $gtm_experiment_id
 * @property string $conversion_definition_version_id
 * @property string $offer_version_id
 * @property string $outreach_policy_version_id
 * @property string|null $model_id
 * @property string|null $ai_task_version
 * @property string|null $prompt_version
 * @property string $schema_version
 * @property string $validator_version
 * @property int $arm_count
 * @property int $account_count
 * @property int $people_count
 * @property int $action_count
 * @property int $maximum_cost_micros
 * @property int $estimated_cost_micros
 * @property int $actual_cost_micros
 * @property array<string, mixed> $input_manifest
 * @property string $evidence_manifest_hash
 * @property array<string, mixed> $compilation_manifest
 * @property string $canonical_payload_hash
 * @property string $plan_hash
 * @property int $created_by_user_id
 * @property Carbon|null $compiled_at
 * @property Carbon|null $superseded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'origin',
    'id', 'workspace_id', 'objective_run_id', 'version', 'status', 'objective_version_id',
    'audience_snapshot_id', 'gtm_experiment_id', 'conversion_definition_version_id',
    'offer_version_id', 'outreach_policy_version_id', 'model_id', 'ai_task_version', 'prompt_version',
    'schema_version', 'validator_version', 'arm_count', 'account_count', 'people_count', 'action_count',
    'maximum_cost_micros', 'estimated_cost_micros', 'actual_cost_micros', 'input_manifest',
    'evidence_manifest_hash', 'compilation_manifest', 'canonical_payload_hash', 'plan_hash',
    'created_by_user_id', 'compiled_at', 'superseded_at',
])]
class ExecutionPlan extends Model
{
    use HasExecutionOrigin;
    use HasUuids;
    use ImmutableRecord;

    protected $attributes = ['origin' => 'ai'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'origin' => ExecutionOrigin::class,
            'version' => 'integer',
            'status' => ExecutionPlanStatus::class,
            'arm_count' => 'integer',
            'account_count' => 'integer',
            'people_count' => 'integer',
            'action_count' => 'integer',
            'maximum_cost_micros' => 'integer',
            'estimated_cost_micros' => 'integer',
            'actual_cost_micros' => 'integer',
            'input_manifest' => 'array',
            'compilation_manifest' => 'array',
            'compiled_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ObjectiveRun, $this> */
    public function objectiveRun(): BelongsTo
    {
        return $this->belongsTo(ObjectiveRun::class);
    }

    /** @return BelongsTo<ObjectiveVersion, $this> */
    public function objectiveVersion(): BelongsTo
    {
        return $this->belongsTo(ObjectiveVersion::class);
    }

    /** @return BelongsTo<AudienceSnapshot, $this> */
    public function audienceSnapshot(): BelongsTo
    {
        return $this->belongsTo(AudienceSnapshot::class);
    }

    /** @return BelongsTo<GtmExperiment, $this> */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(GtmExperiment::class, 'gtm_experiment_id');
    }

    /** @return BelongsTo<ConversionDefinitionVersion, $this> */
    public function conversionDefinitionVersion(): BelongsTo
    {
        return $this->belongsTo(ConversionDefinitionVersion::class);
    }

    /** @return BelongsTo<OfferVersion, $this> */
    public function offerVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class);
    }

    /** @return BelongsTo<OutreachPolicyVersion, $this> */
    public function outreachPolicyVersion(): BelongsTo
    {
        return $this->belongsTo(OutreachPolicyVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<ExecutionPlanAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ExecutionPlanAction::class);
    }

    /** @return HasMany<ExecutionPlanCompilation, $this> */
    public function compilations(): HasMany
    {
        return $this->hasMany(ExecutionPlanCompilation::class);
    }

    /** @return HasMany<ExecutionPlanCampaign, $this> */
    public function compiledCampaigns(): HasMany
    {
        return $this->hasMany(ExecutionPlanCampaign::class);
    }

    /** @return HasMany<ApprovalBatch, $this> */
    public function approvalBatches(): HasMany
    {
        return $this->hasMany(ApprovalBatch::class);
    }

    /** @return HasMany<PolicyEvaluation, $this> */
    public function policyEvaluations(): HasMany
    {
        return $this->hasMany(PolicyEvaluation::class);
    }
}
