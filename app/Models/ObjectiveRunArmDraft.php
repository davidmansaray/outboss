<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\HasExecutionOrigin;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ObjectiveRunArmDraftState;
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
 * @property string $gtm_experiment_arm_id
 * @property string|null $ai_budget_run_id
 * @property string|null $ai_provider_operation_id
 * @property ObjectiveRunArmDraftState $state
 * @property string|null $prompt_version
 * @property string $schema_version
 * @property string $validator_version
 * @property string|null $model_id
 * @property int|null $task_policy_version
 * @property string $input_hash
 * @property string|null $subject_template
 * @property string|null $body_template
 * @property array<string, mixed>|null $claim_manifest
 * @property array<string, mixed>|null $cta_manifest
 * @property string|null $output_hash
 * @property string|null $template_hash
 * @property string|null $safe_error_code
 * @property string|null $safe_error_detail
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property-read GtmExperimentArm|null $arm
 * @property-read AiBudgetRun|null $budgetRun
 * @property-read AiProviderOperation|null $providerOperation
 */
#[Fillable([
    'origin', 'authored_by_user_id', 'authored_at',
    'id', 'workspace_id', 'objective_run_id', 'gtm_experiment_arm_id', 'ai_budget_run_id',
    'ai_provider_operation_id', 'state', 'prompt_version', 'schema_version', 'validator_version',
    'model_id', 'task_policy_version', 'input_hash', 'subject_template', 'body_template', 'claim_manifest', 'cta_manifest',
    'output_hash', 'template_hash', 'safe_error_code', 'safe_error_detail', 'started_at', 'finished_at',
])]
class ObjectiveRunArmDraft extends Model
{
    use HasExecutionOrigin;
    use HasUuids;

    protected $attributes = ['origin' => 'ai'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'origin' => ExecutionOrigin::class,
            'authored_at' => 'datetime',
            'state' => ObjectiveRunArmDraftState::class,
            'task_policy_version' => 'integer',
            'claim_manifest' => 'array',
            'cta_manifest' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    /** @return BelongsTo<GtmExperimentArm, $this> */
    public function arm(): BelongsTo
    {
        return $this->belongsTo(GtmExperimentArm::class, 'gtm_experiment_arm_id');
    }

    /** @return BelongsTo<AiBudgetRun, $this> */
    public function budgetRun(): BelongsTo
    {
        return $this->belongsTo(AiBudgetRun::class, 'ai_budget_run_id');
    }

    /** @return BelongsTo<AiProviderOperation, $this> */
    public function providerOperation(): BelongsTo
    {
        return $this->belongsTo(AiProviderOperation::class, 'ai_provider_operation_id');
    }

    /** @return HasMany<ExecutionPlanAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ExecutionPlanAction::class);
    }
}
