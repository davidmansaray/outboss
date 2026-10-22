<?php

namespace App\Models;

use App\Domain\Campaigns\Enums\ImportRowIssue;
use Database\Factories\RecipientImportRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'recipient_import_id',
    'row_number',
    'raw_values',
    'email_address',
    'email_normalized',
    'full_name',
    'first_name',
    'company',
    'issue',
    'row_hash',
])]
class RecipientImportRow extends Model
{
    /** @use HasFactory<RecipientImportRowFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'issue' => ImportRowIssue::class,
            'raw_values' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(RecipientImport::class, 'recipient_import_id');
    }
}
