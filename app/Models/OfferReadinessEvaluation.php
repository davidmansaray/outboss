<?php

namespace App\Models;

use App\Domain\Gtm\Enums\OfferReadinessState;
use App\Domain\Gtm\Support\ImmutableVersion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property OfferReadinessState $state
 * @property list<array{code: string, message: string}> $blockers
 * @property Carbon|null $valid_until
 */
#[Fillable([
    'id', 'workspace_id', 'offer_id', 'offer_version_id', 'evaluator_version',
    'state', 'blockers', 'dependency_snapshot', 'dependency_hash', 'trigger',
    'evaluated_at', 'valid_until', 'created_at',
])]
class OfferReadinessEvaluation extends Model
{
    use HasUuids;
    use ImmutableVersion;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'evaluator_version' => 'integer',
            'state' => OfferReadinessState::class,
            'blockers' => 'array',
            'dependency_snapshot' => 'array',
            'evaluated_at' => 'datetime',
            'valid_until' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
