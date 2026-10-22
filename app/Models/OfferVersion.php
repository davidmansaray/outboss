<?php

namespace App\Models;

use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Enums\OfferVersionOrigin;
use App\Domain\Gtm\Support\ImmutableVersion;
use Database\Factories\OfferVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed> $payload
 * @property OfferStatus $status_snapshot
 */
#[Fillable([
    'id',
    'workspace_id',
    'offer_id',
    'version',
    'schema_version',
    'payload',
    'status_snapshot',
    'content_hash',
    'origin',
    'from_status',
    'actor_type',
    'actor_user_id',
    'actor_display_name',
    'created_at',
])]
class OfferVersion extends Model
{
    /** @use HasFactory<OfferVersionFactory> */
    use HasFactory;

    use HasUuids;
    use ImmutableVersion;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status_snapshot' => OfferStatus::class,
            'from_status' => OfferStatus::class,
            'origin' => OfferVersionOrigin::class,
            'version' => 'integer',
            'schema_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
