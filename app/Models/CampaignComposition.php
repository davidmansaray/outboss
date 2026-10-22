<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Immutable human selection revision. Its existence requires governed execution.
 *
 * @property Carbon|null $sending_starts_at
 * @property Carbon|null $sending_ends_at
 */
#[Fillable(['workspace_id', 'campaign_id', 'revision', 'objective_goal_version_id', 'offer_version_id', 'execution_authority_scope_id', 'target_contribution_count', 'sending_starts_at', 'sending_ends_at', 'operation_id', 'request_hash', 'created_by_user_id'])]
class CampaignComposition extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['revision' => 'integer', 'target_contribution_count' => 'integer', 'sending_starts_at' => 'datetime', 'sending_ends_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
