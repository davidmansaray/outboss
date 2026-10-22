<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Enums\AiTask;
use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetSetting;
use App\Models\AiTaskSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AiWorkspaceSettings
{
    public function __construct(private readonly AiEventRecorder $events) {}

    public function ensureDefaults(Workspace $workspace): void
    {
        DB::transaction(function () use ($workspace): void {
            AiBudgetSetting::query()->firstOrCreate(
                ['workspace_id' => $workspace->id],
                [
                    'currency' => 'USD',
                    'monthly_limit_micros' => (int) config('ai.default_monthly_limit_micros'),
                    'tavily_credit_price_micros' => (int) config('ai.tavily_default_micros_per_credit'),
                    'version' => 1,
                ],
            );

            foreach (config('ai.tasks', []) as $task => $definition) {
                AiTaskSetting::query()->firstOrCreate(
                    ['workspace_id' => $workspace->id, 'task' => $task],
                    [
                        'model_id' => $definition['model_id'],
                        'per_run_limit_micros' => $definition['per_run_limit_micros'],
                        'policy_version' => 1,
                    ],
                );
            }
        }, 3);
    }

    public function updateTask(Workspace $workspace, User $actor, AiTask $task, string $modelId, int $perRunLimitMicros): AiTaskSetting
    {
        if (! in_array($task->value, AiTask::productValues(), true)) {
            throw new InvalidArgumentException('Connection tests do not have an editable task policy.');
        }

        $models = config('ai.models', []);
        $model = is_array($models) ? ($models[$modelId] ?? null) : null;
        if (! is_array($model) || ! in_array($task->value, $model['tasks'] ?? [], true)) {
            throw new InvalidArgumentException('That model is not approved for this task.');
        }

        return DB::transaction(function () use ($workspace, $actor, $task, $modelId, $perRunLimitMicros): AiTaskSetting {
            $setting = AiTaskSetting::query()
                ->where('workspace_id', $workspace->id)
                ->where('task', $task->value)
                ->lockForUpdate()
                ->firstOrFail();
            $from = $setting->model_id;
            $setting->forceFill([
                'model_id' => $modelId,
                'per_run_limit_micros' => $perRunLimitMicros,
                'policy_version' => (int) $setting->policy_version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->events->record($workspace, 'model_changed', $actor, metadata: [
                'task' => $task->value,
                'from_model' => $from,
                'to_model' => $modelId,
                'per_run_limit_micros' => $perRunLimitMicros,
            ]);

            return $setting->fresh();
        }, 3);
    }

    public function updateBudget(Workspace $workspace, User $actor, int $monthlyLimitMicros, int $tavilyCreditPriceMicros): AiBudgetSetting
    {
        return DB::transaction(function () use ($workspace, $actor, $monthlyLimitMicros, $tavilyCreditPriceMicros): AiBudgetSetting {
            $settings = AiBudgetSetting::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $fromLimit = (int) $settings->monthly_limit_micros;
            $fromPrice = (int) $settings->tavily_credit_price_micros;
            $settings->forceFill([
                'monthly_limit_micros' => $monthlyLimitMicros,
                'tavily_credit_price_micros' => $tavilyCreditPriceMicros,
                'version' => (int) $settings->version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();

            $start = Date::now('UTC')->startOfMonth()->toDateString();
            $period = AiBudgetPeriod::query()
                ->where('workspace_id', $workspace->id)
                ->whereDate('period_start_utc', $start)
                ->lockForUpdate()
                ->first();

            if ($period !== null) {
                $period->forceFill([
                    'limit_snapshot_micros' => $monthlyLimitMicros,
                    'settings_version' => $settings->version,
                    'over_limit' => $period->budget_breach || (int) $period->held_micros + (int) $period->committed_micros >= $monthlyLimitMicros,
                ])->save();
            }

            $this->events->record($workspace, 'budget_changed', $actor, metadata: [
                'from_monthly_limit_micros' => $fromLimit,
                'to_monthly_limit_micros' => $monthlyLimitMicros,
            ]);
            if ($fromPrice !== $tavilyCreditPriceMicros) {
                $this->events->record($workspace, 'pricing_basis_changed', $actor, metadata: [
                    'from_tavily_credit_price_micros' => $fromPrice,
                    'to_tavily_credit_price_micros' => $tavilyCreditPriceMicros,
                ]);
            }

            return $settings->fresh();
        }, 3);
    }

    public function expandsSpendingAuthority(Workspace $workspace, int $monthlyLimitMicros, int $tavilyCreditPriceMicros): bool
    {
        $settings = AiBudgetSetting::query()->whereKey($workspace->id)->firstOrFail();

        return $monthlyLimitMicros > (int) $settings->monthly_limit_micros
            || $tavilyCreditPriceMicros < (int) $settings->tavily_credit_price_micros;
    }
}
