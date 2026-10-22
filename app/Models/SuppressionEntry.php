<?php

namespace App\Models;

use App\Domain\Mail\Enums\SuppressionReason;
use Database\Factories\SuppressionEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property SuppressionReason $reason */
#[Fillable([
    'user_id',
    'workspace_id',
    'email_address',
    'email_normalized',
    'reason',
    'source_message_id',
])]
class SuppressionEntry extends Model
{
    /** @use HasFactory<SuppressionEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'reason' => SuppressionReason::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }
}
