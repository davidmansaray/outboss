<?php

namespace App\Models;

use App\Domain\Conversions\Enums\ConversionEventState;
use App\Domain\Conversions\Enums\ConversionReviewAction;
use Database\Factories\ConversionEventReviewEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'id',
    'workspace_id',
    'conversion_event_id',
    'action',
    'actor_user_id',
    'actor_display_name',
    'reason',
    'prior_state',
    'projected_state',
    'successor_event_id',
    'successor_match_id',
    'created_at',
])]
class ConversionEventReviewEvent extends Model
{
    /** @use HasFactory<ConversionEventReviewEventFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Conversion event review events are append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Conversion event review events are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'action' => ConversionReviewAction::class,
            'prior_state' => ConversionEventState::class,
            'projected_state' => ConversionEventState::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace,$this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ConversionEvent,$this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ConversionEvent::class, 'conversion_event_id');
    }

    /** @return BelongsTo<User,$this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<ConversionEvent,$this> */
    public function successorEvent(): BelongsTo
    {
        return $this->belongsTo(ConversionEvent::class, 'successor_event_id');
    }

    /** @return BelongsTo<ConversionEventMatch,$this> */
    public function successorMatch(): BelongsTo
    {
        return $this->belongsTo(ConversionEventMatch::class, 'successor_match_id');
    }
}
