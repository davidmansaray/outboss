<?php

namespace App\Models;

use Database\Factories\WorkspaceClientOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int|null $initiating_user_id */
#[Fillable([
    'initiating_user_id',
    'client_operation_id',
    'server_fingerprint',
    'resulting_workspace_id',
    'redirect_route',
    'redirect_parameters',
    'response_metadata',
])]
class WorkspaceClientOperation extends Model
{
    /** @use HasFactory<WorkspaceClientOperationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'redirect_parameters' => 'array',
            'response_metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function initiatingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiating_user_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function resultingWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'resulting_workspace_id');
    }
}
