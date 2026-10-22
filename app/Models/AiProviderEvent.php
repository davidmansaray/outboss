<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $event
 * @property string $actor_type
 * @property string|null $from_state
 * @property string|null $to_state
 * @property array<string, bool|int|string|null>|null $safe_metadata
 * @property CarbonImmutable $created_at
 */
#[Fillable(['workspace_id', 'credential_id', 'credential_version_id', 'actor_user_id', 'actor_type', 'event', 'from_state', 'to_state', 'safe_metadata', 'created_at'])]
class AiProviderEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['safe_metadata' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
