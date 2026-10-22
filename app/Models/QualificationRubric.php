<?php

namespace App\Models;

use App\Domain\Intelligence\Enums\QualificationRubricState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'workspace_id', 'name', 'state', 'current_version_id', 'lock_version'])]
class QualificationRubric extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'state' => QualificationRubricState::class,
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<QualificationRubricVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(QualificationRubricVersion::class, 'current_version_id');
    }

    /** @return HasMany<QualificationRubricVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(QualificationRubricVersion::class, 'rubric_id');
    }
}
