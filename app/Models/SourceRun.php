<?php

namespace App\Models;

use App\Domain\Prospecting\Enums\SourceRunState;
use Database\Factories\SourceRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id',
    'workspace_id',
    'prospect_source_id',
    'prospect_source_version_id',
    'state',
    'initiator_type',
    'initiated_by_user_id',
    'initiator_display_name',
    'idempotency_key',
    'request_fingerprint',
    'retry_of_run_id',
    'person_import_id',
    'recipient_import_id',
    'input_reference',
    'checkpoint',
    'received',
    'valid',
    'accepted',
    'created',
    'linked',
    'duplicate',
    'conflicted',
    'suppressed',
    'rejected',
    'expired',
    'estimated_cost_micros',
    'reserved_cost_micros',
    'actual_cost_micros',
    'lease_owner',
    'lease_expires_at',
    'retry_after_at',
    'safe_error_code',
    'safe_error_message',
    'started_at',
    'finished_at',
    'cancelled_at',
])]
class SourceRun extends Model
{
    /** @use HasFactory<SourceRunFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'state' => SourceRunState::class,
            'input_reference' => 'array',
            'checkpoint' => 'array',
            'lease_expires_at' => 'datetime',
            'retry_after_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'received' => 'integer',
            'valid' => 'integer',
            'accepted' => 'integer',
            'created' => 'integer',
            'linked' => 'integer',
            'duplicate' => 'integer',
            'conflicted' => 'integer',
            'suppressed' => 'integer',
            'rejected' => 'integer',
            'expired' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ProspectSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ProspectSource::class, 'prospect_source_id');
    }

    /** @return BelongsTo<ProspectSourceVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProspectSourceVersion::class, 'prospect_source_version_id');
    }

    /** @return BelongsTo<SourceRun, $this> */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /** @return HasMany<SourceObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(SourceObservation::class);
    }
}
