<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'execution_authority_scope_id', 'created_by_user_id', 'reason'])]
class ExecutionAuthorityRevocation extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $timestamps = false;
}
