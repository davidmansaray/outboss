<?php

namespace App\Models;

use App\Domain\Prospecting\Enums\CanonicalFieldDecision;
use Database\Factories\CanonicalFieldProvenanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'public_id',
    'workspace_id',
    'entity_type',
    'account_id',
    'person_id',
    'field_path',
    'source_observation_id',
    'source_run_id',
    'decision',
    'prior_value_hash',
    'new_value_hash',
    'actor_type',
    'actor_user_id',
    'actor_display_name',
    'created_at',
])]
class CanonicalFieldProvenance extends Model
{
    /** @use HasFactory<CanonicalFieldProvenanceFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'canonical_field_provenance';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Canonical field provenance is append-only.');
        });
        static::deleting(function (): never {
            throw new LogicException('Canonical field provenance is append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'decision' => CanonicalFieldDecision::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<SourceObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(SourceObservation::class, 'source_observation_id');
    }

    /** @return BelongsTo<SourceRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SourceRun::class, 'source_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
