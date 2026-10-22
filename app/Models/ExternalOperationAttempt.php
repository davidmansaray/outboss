<?php

namespace App\Models;

use App\Domain\Integrations\Enums\ExternalOperationAttemptKind;
use App\Domain\Integrations\Enums\ExternalOperationAttemptStatus;
use Database\Factories\ExternalOperationAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $operation_id
 * @property int $attempt_number
 * @property ExternalOperationAttemptKind $kind
 * @property ExternalOperationAttemptStatus $status
 */
#[Fillable([
    'operation_id',
    'attempt_number',
    'kind',
    'status',
    'provider_request_id',
    'provider_snapshot_id',
    'safe_error_code',
    'http_status',
    'retry_after_at',
    'latency_ms',
])]
class ExternalOperationAttempt extends Model
{
    /** @use HasFactory<ExternalOperationAttemptFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'kind' => ExternalOperationAttemptKind::class,
            'status' => ExternalOperationAttemptStatus::class,
            'http_status' => 'integer',
            'retry_after_at' => 'datetime',
            'latency_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<ExternalOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(ExternalOperation::class, 'operation_id');
    }
}
