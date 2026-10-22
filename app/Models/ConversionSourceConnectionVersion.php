<?php

namespace App\Models;

use App\Domain\Conversions\Enums\ConversionCredentialStatus;
use Database\Factories\ConversionSourceConnectionVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string|null $encrypted_secret */
#[Fillable([
    'id',
    'workspace_id',
    'connection_id',
    'version',
    'status',
    'encrypted_secret',
    'allowed_conversion_definition_ids',
    'allowed_source_types',
    'signature_algorithm',
    'payload_limit_bytes',
    'replay_window_seconds',
    'valid_from',
    'overlap_until',
    'ip_policy',
    'rate_policy',
    'created_by_user_id',
    'created_at',
    'retired_at',
    'wiped_at',
])]
#[Hidden(['encrypted_secret'])]
class ConversionSourceConnectionVersion extends Model
{
    /** @use HasFactory<ConversionSourceConnectionVersionFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'encrypted_secret' => 'encrypted',
            'status' => ConversionCredentialStatus::class,
            'version' => 'integer',
            'allowed_conversion_definition_ids' => 'array',
            'allowed_source_types' => 'array',
            'ip_policy' => 'array',
            'rate_policy' => 'array',
            'payload_limit_bytes' => 'integer',
            'replay_window_seconds' => 'integer',
            'valid_from' => 'datetime',
            'overlap_until' => 'datetime',
            'created_at' => 'datetime',
            'retired_at' => 'datetime',
            'wiped_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace,$this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ConversionSourceConnection,$this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ConversionSourceConnection::class, 'connection_id');
    }

    /** @return BelongsTo<User,$this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function canVerifyAt(\DateTimeInterface $at): bool
    {
        if (ConversionCredentialStatus::tryFrom((string) $this->getRawOriginal('status')) === ConversionCredentialStatus::Wiped || $this->getAttribute('wiped_at') !== null) {
            return false;
        }
        $when = $at->getTimestamp();
        $validFrom = $this->getAttribute('valid_from');
        $overlapUntil = $this->getAttribute('overlap_until');
        $from = $validFrom instanceof \DateTimeInterface ? $validFrom->getTimestamp() : PHP_INT_MIN;
        $until = $overlapUntil instanceof \DateTimeInterface ? $overlapUntil->getTimestamp() : PHP_INT_MAX;

        return $when >= $from && $when <= $until;
    }
}
