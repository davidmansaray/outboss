<?php

namespace App\Models;

use App\Domain\Ai\Enums\AiTask;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property AiTask $task
 * @property string $model_id
 * @property int $per_run_limit_micros
 * @property int $policy_version
 */
#[Fillable(['workspace_id', 'task', 'model_id', 'per_run_limit_micros', 'policy_version', 'updated_by_user_id'])]
class AiTaskSetting extends Model
{
    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'per_run_limit_micros' => 'integer',
            'policy_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
