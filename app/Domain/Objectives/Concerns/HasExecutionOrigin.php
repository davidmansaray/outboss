<?php

namespace App\Domain\Objectives\Concerns;

use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ObjectiveRunArmDraftState;
use App\Models\ExecutionPlan;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use LogicException;

trait HasExecutionOrigin
{
    public static function bootHasExecutionOrigin(): void
    {
        static::saving(function (self $record): void {
            if ($record->exists && $record->isDirty('origin')) {
                throw new LogicException('Execution origin cannot change after creation.');
            }
            $experimentField = $record instanceof ObjectiveRunArmDraft ? 'gtm_experiment_arm_id' : 'gtm_experiment_id';
            if ($record->getAttribute('origin') !== ExecutionOrigin::Human) {
                if ($record->getAttribute($experimentField) === null) {
                    throw new LogicException('AI execution requires its experiment lineage.');
                }

                return;
            }
            if ($record->getAttribute($experimentField) !== null) {
                throw new LogicException('Human execution uses campaign lineage, not an experiment.');
            }
            $aiFields = match (true) {
                $record instanceof ObjectiveRun => ['ai_budget_envelope_id', 'message_validator_version'],
                $record instanceof ObjectiveRunArmDraft => ['ai_budget_run_id', 'ai_provider_operation_id', 'model_id', 'prompt_version', 'task_policy_version'],
                $record instanceof ExecutionPlan => ['model_id', 'ai_task_version', 'prompt_version'],
            };
            foreach ($aiFields as $field) {
                if ($record->getAttribute($field) !== null) {
                    throw new LogicException('Human execution cannot carry AI provider or generation metadata.');
                }
            }
            foreach (['maximum_cost_micros', 'reserved_cost_micros', 'estimated_cost_micros', 'actual_cost_micros'] as $field) {
                if ((int) $record->getAttribute($field) !== 0) {
                    throw new LogicException('Human execution cannot reserve or report AI costs.');
                }
            }
            if ($record instanceof ObjectiveRun && $record->getAttribute('campaign_composition_id') === null) {
                throw new LogicException('Human execution requires pinned campaign composition.');
            }
            if ($record instanceof ObjectiveRunArmDraft && $record->state === ObjectiveRunArmDraftState::Succeeded
                && ($record->getAttribute('authored_by_user_id') === null || $record->getAttribute('authored_at') === null)) {
                throw new LogicException('A human-authored draft requires an author and timestamp.');
            }
        });
    }
}
