<?php

namespace App\Models;

use Database\Factories\GtmProfileClientOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'gtm_profile_id',
    'client_operation_id',
    'server_fingerprint',
    'expected_lock_version',
    'resulting_lock_version',
    'expected_version_id',
    'resulting_version_id',
    'response_metadata',
])]
class GtmProfileClientOperation extends Model
{
    /** @use HasFactory<GtmProfileClientOperationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expected_lock_version' => 'integer',
            'resulting_lock_version' => 'integer',
            'response_metadata' => 'array',
        ];
    }

    /** @return BelongsTo<GtmProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(GtmProfile::class, 'gtm_profile_id');
    }

    /** @return BelongsTo<GtmProfileVersion, $this> */
    public function expectedVersion(): BelongsTo
    {
        return $this->belongsTo(GtmProfileVersion::class, 'expected_version_id');
    }

    /** @return BelongsTo<GtmProfileVersion, $this> */
    public function resultingVersion(): BelongsTo
    {
        return $this->belongsTo(GtmProfileVersion::class, 'resulting_version_id');
    }
}
