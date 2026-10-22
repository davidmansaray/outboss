<?php

namespace App\Models;

use Database\Factories\PersonImportRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<int, string> $tags
 */
#[Fillable([
    'person_import_id',
    'row_number',
    'raw_values',
    'email_address',
    'email_normalized',
    'full_name',
    'first_name',
    'job_title',
    'company',
    'tags',
    'issue',
    'action',
    'row_hash',
])]
class PersonImportRow extends Model
{
    /** @use HasFactory<PersonImportRowFactory> */
    use HasFactory;

    public const ISSUE_NEW = 'new';

    public const ISSUE_UPDATED = 'updated';

    public const ISSUE_INVALID = 'invalid';

    public const ISSUE_DUPLICATE = 'duplicate';

    public const ISSUE_SUPPRESSED = 'suppressed';

    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_SKIP = 'skip';

    protected function casts(): array
    {
        return [
            'raw_values' => 'array',
            'tags' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PersonImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(PersonImport::class, 'person_import_id');
    }
}
