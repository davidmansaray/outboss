<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Durable replay record for version/archive Asset operations. */
/**
 * @property int $workspace_id
 * @property string $asset_id
 * @property string $client_operation_id
 * @property string $server_fingerprint
 * @property int|null $expected_lock_version
 * @property int|null $resulting_lock_version
 * @property string|null $expected_version_id
 * @property string $resulting_version_id
 * @property array<string,mixed>|null $response_metadata
 */
#[Fillable([
    'workspace_id', 'asset_id', 'client_operation_id', 'server_fingerprint',
    'expected_lock_version', 'resulting_lock_version', 'expected_version_id',
    'resulting_version_id', 'response_metadata',
])]
#[Hidden(['server_fingerprint'])]
class AssetClientOperation extends Model
{
    protected function casts(): array
    {
        return [
            'workspace_id' => 'integer',
            'expected_lock_version' => 'integer',
            'resulting_lock_version' => 'integer',
            'response_metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<AssetVersion, $this> */
    public function expectedVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class, 'expected_version_id');
    }

    /** @return BelongsTo<AssetVersion, $this> */
    public function resultingVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class, 'resulting_version_id');
    }
}
