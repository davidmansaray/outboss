<?php

namespace App\Models;

use App\Domain\Campaigns\Enums\ImportStatus;
use Database\Factories\PersonImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ImportStatus $status
 */
#[Fillable([
    'workspace_id',
    'user_id',
    'original_filename',
    'source_hash',
    'headers',
    'mapping',
    'mapping_version',
    'status',
    'total',
    'new',
    'updated',
    'invalid',
    'duplicate',
    'suppressed',
    'imported',
    'error',
    'expires_at',
])]
class PersonImport extends Model
{
    /** @use HasFactory<PersonImportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'headers' => 'array',
            'mapping' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PersonImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(PersonImportRow::class);
    }
}
