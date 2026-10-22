<?php

namespace App\Models;

use App\Domain\Conversions\Enums\ConversionIntakeState;
use App\Domain\Conversions\Enums\ConversionSourceType;
use App\Domain\Conversions\Enums\ConversionVerificationState;
use Database\Factories\ConversionEventReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The receipt is the durable boundary for a source delivery. Its namespace
 * is always non-null so SQL NULL uniqueness cannot bypass deduplication.
 */
#[Fillable([
    'id',
    'workspace_id',
    'connection_id',
    'connection_version_id',
    'manual_actor_id',
    'source_namespace',
    'source_type',
    'external_event_id',
    'dedupe_key',
    'dedupe_hash',
    'payload_hash',
    'schema_version',
    'received_at',
    'occurred_at',
    'verification_state',
    'intake_state',
    'safe_rejection_code',
    'unmatched_identity_envelope',
    'identity_expires_at',
    'identity_redacted_at',
    'conversion_event_id',
    'actor_display_name',
    'created_at',
    'updated_at',
])]
class ConversionEventReceipt extends Model
{
    /** @use HasFactory<ConversionEventReceiptFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static bool $projectionWrite = false;

    protected static function booted(): void
    {
        static::updating(static function (): void {
            if (! self::$projectionWrite) {
                throw new LogicException('Conversion event receipts are append-only.');
            }
        });
        static::deleting(static function (): void {
            throw new LogicException('Conversion event receipts are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'source_type' => ConversionSourceType::class,
            'verification_state' => ConversionVerificationState::class,
            'intake_state' => ConversionIntakeState::class,
            'schema_version' => 'integer',
            'received_at' => 'datetime',
            'occurred_at' => 'datetime',
            'unmatched_identity_envelope' => 'encrypted:array',
            'identity_expires_at' => 'datetime',
            'identity_redacted_at' => 'datetime',
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

    /** @return BelongsTo<ConversionSourceConnectionVersion,$this> */
    public function connectionVersion(): BelongsTo
    {
        return $this->belongsTo(ConversionSourceConnectionVersion::class, 'connection_version_id');
    }

    /** @return BelongsTo<User,$this> */
    public function manualActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_actor_id');
    }

    /** @return BelongsTo<ConversionEvent,$this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ConversionEvent::class, 'conversion_event_id');
    }

    /**
     * Receipt envelope expiry/redaction is a projection maintained by the
     * retention service. Source payload hashes and audit state remain fixed.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function project(array $attributes): bool
    {
        self::$projectionWrite = true;
        try {
            return $this->forceFill($attributes)->saveQuietly();
        } finally {
            self::$projectionWrite = false;
        }
    }
}
