<?php

namespace App\Models;

use App\Domain\Gtm\Enums\OfferReadinessState;
use App\Domain\Gtm\Support\ImmutableVersion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property OfferReadinessState|null $from_state
 * @property OfferReadinessState $to_state
 */
#[Fillable([
    'id', 'workspace_id', 'offer_id', 'offer_version_id', 'action',
    'from_state', 'to_state', 'actor_user_id', 'actor_display_name',
    'reason', 'policy_hash', 'created_at',
])]
class OfferVersionReviewEvent extends Model
{
    use HasUuids;
    use ImmutableVersion;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'from_state' => OfferReadinessState::class,
            'to_state' => OfferReadinessState::class,
            'created_at' => 'datetime',
        ];
    }
}
