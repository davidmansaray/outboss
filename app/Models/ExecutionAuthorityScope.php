<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property array<string,mixed> $authority */
#[Fillable(['workspace_id', 'name', 'authority', 'content_hash', 'operation_id', 'created_by_user_id'])]
class ExecutionAuthorityScope extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['authority' => 'array', 'created_at' => 'datetime'];
    }
}
