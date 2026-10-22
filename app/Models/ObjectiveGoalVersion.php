<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Reusable business intent; never sufficient authority for execution.
 *
 * @property Carbon|null $deadline
 * @property array<string,mixed>|null $execution_authority
 */
#[Fillable(['workspace_id', 'objective_id', 'version', 'name', 'description', 'measurement', 'target_count', 'deadline', 'creation_key', 'content_hash', 'created_by_user_id', 'conversion_definition_version_id', 'execution_authority'])]
class ObjectiveGoalVersion extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['version' => 'integer', 'target_count' => 'integer', 'deadline' => 'date', 'created_at' => 'datetime', 'execution_authority' => 'array'];
    }
}
