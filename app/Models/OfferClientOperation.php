<?php

namespace App\Models;

use Database\Factories\OfferClientOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $resulting_lock_version
 * @property string|null $resulting_version_id
 * @property array<string, mixed>|null $response_metadata
 */
#[Fillable([
    'offer_id',
    'client_operation_id',
    'server_fingerprint',
    'expected_lock_version',
    'resulting_lock_version',
    'expected_version_id',
    'resulting_version_id',
    'response_metadata',
])]
class OfferClientOperation extends Model
{
    /** @use HasFactory<OfferClientOperationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expected_lock_version' => 'integer',
            'resulting_lock_version' => 'integer',
            'response_metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<OfferVersion, $this> */
    public function expectedVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class, 'expected_version_id');
    }

    /** @return BelongsTo<OfferVersion, $this> */
    public function resultingVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class, 'resulting_version_id');
    }
}
