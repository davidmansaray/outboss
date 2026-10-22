<?php

namespace App\Models;

use App\Domain\Mail\Enums\SendResolution;
use Database\Factories\SendAttemptResolutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'send_attempt_id',
    'review_cycle',
    'resolution',
    'operator_identifier',
    'safe_evidence',
])]
class SendAttemptResolution extends Model
{
    /** @use HasFactory<SendAttemptResolutionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'resolution' => SendResolution::class,
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(SendAttempt::class, 'send_attempt_id');
    }
}
