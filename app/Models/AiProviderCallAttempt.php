<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['operation_id', 'attempt_number', 'kind', 'status', 'provider_request_id', 'provider_generation_id', 'safe_error_code', 'safe_metadata', 'fencing_token', 'http_status', 'retry_after_at', 'latency_ms'])]
class AiProviderCallAttempt extends Model
{
    protected function casts(): array
    {
        return ['retry_after_at' => 'datetime', 'attempt_number' => 'integer', 'fencing_token' => 'integer', 'safe_metadata' => 'array', 'http_status' => 'integer', 'latency_ms' => 'integer'];
    }

    /** @return BelongsTo<AiProviderOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(AiProviderOperation::class, 'operation_id');
    }
}
