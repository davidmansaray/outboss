<?php

namespace App\Models;

use App\Domain\Intelligence\Enums\ResearchDispatchState;
use Database\Factories\AccountResearchDispatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id', 'workspace_id', 'research_run_id', 'dispatch_kind', 'stage_key', 'state', 'available_at',
    'published_at', 'publish_lease_owner', 'publish_lease_expires_at', 'publish_attempts', 'safe_error_code',
])]
class AccountResearchDispatch extends Model
{
    /** @use HasFactory<AccountResearchDispatchFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'state' => ResearchDispatchState::class,
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'publish_lease_expires_at' => 'datetime',
            'publish_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<AccountResearchRun, $this> */
    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(AccountResearchRun::class, 'research_run_id');
    }
}
