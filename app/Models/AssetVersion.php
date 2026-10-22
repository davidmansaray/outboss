<?php

namespace App\Models;

use App\Domain\Gtm\Support\ImmutableVersion;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $workspace_id
 * @property string $asset_id
 * @property int $version
 * @property string $title
 * @property string|null $description
 * @property string $external_url
 * @property string $media_type
 * @property string|null $source
 * @property string $content_hash
 * @property array<string|int,mixed> $access_instructions
 * @property string $approval_state
 * @property array<string|int,mixed> $intended_audience
 * @property Carbon|null $expires_at
 * @property string $availability_state
 * @property string|null $licence
 * @property string $classification
 * @property array<string|int,mixed> $delivery_restrictions
 * @property array<string|int,mixed> $tracking_metadata
 * @property int|null $actor_user_id
 * @property string|null $actor_display_name
 * @property Carbon|null $created_at
 */
#[Fillable([
    'id', 'workspace_id', 'asset_id', 'version', 'title', 'description',
    'external_url', 'media_type', 'source', 'content_hash',
    'access_instructions', 'approval_state', 'intended_audience', 'expires_at',
    'availability_state', 'licence', 'classification', 'delivery_restrictions',
    'tracking_metadata', 'actor_user_id', 'actor_display_name', 'created_at',
])]
class AssetVersion extends Model
{
    use HasUuids;
    use ImmutableVersion;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'access_instructions' => 'array',
            'intended_audience' => 'array',
            'expires_at' => 'datetime',
            'delivery_restrictions' => 'array',
            'tracking_metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
