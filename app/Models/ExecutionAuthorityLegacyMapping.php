<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'objective_id', 'source_goal_version_id', 'execution_authority_scope_id', 'created_by_user_id'])]
class ExecutionAuthorityLegacyMapping extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $timestamps = false;
}
