<?php

namespace App\Models;

use App\Domain\Prospecting\Enums\ExternalIdentityState;
use Database\Factories\ExternalIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id',
    'workspace_id',
    'entity_type',
    'account_id',
    'person_id',
    'provider',
    'identity_type',
    'value',
    'value_hash',
    'first_source_observation_id',
    'last_source_observation_id',
    'first_observed_at',
    'last_observed_at',
    'state',
    'policy_hash',
    'retain_until',
])]
class ExternalIdentity extends Model
{
    /** @use HasFactory<ExternalIdentityFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'state' => ExternalIdentityState::class,
            // The provider value is only needed when a provider lookup or
            // source deletion explicitly requires it. Identity matching must
            // always use the deterministic, non-reversible value_hash below.
            'value' => 'encrypted',
            'first_observed_at' => 'datetime',
            'last_observed_at' => 'datetime',
            'retain_until' => 'datetime',
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
    public function firstObservation(): BelongsTo
    {
        return $this->belongsTo(SourceObservation::class, 'first_source_observation_id');
    }

    /** @return BelongsTo<SourceObservation, $this> */
    public function lastObservation(): BelongsTo
    {
        return $this->belongsTo(SourceObservation::class, 'last_source_observation_id');
    }
}
