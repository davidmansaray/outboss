<?php

namespace App\Models;

use App\Domain\Conversions\Enums\ConversionMatchResult;
use Database\Factories\ConversionEventMatchFactory;
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
    'attempt_number',
    'supplied_identity_hashes',
    'identity_handles',
    'resolution_path',
    'candidate_count',
    'result',
    'matched_account_id',
    'actor_user_id',
    'actor_display_name',
    'reason',
    'created_at',
])]
class ConversionEventMatch extends Model
{
    /** @use HasFactory<ConversionEventMatchFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Conversion event matches are append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Conversion event matches are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'supplied_identity_hashes' => 'array',
            'identity_handles' => 'array',
            'candidate_count' => 'integer',
            'result' => ConversionMatchResult::class,
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

    /** @return BelongsTo<Account,$this> */
    public function matchedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'matched_account_id');
    }

    /** @return BelongsTo<User,$this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
