<?php

namespace App\Models;

use App\Domain\Prospecting\Enums\MembershipState;
use Database\Factories\PersonSourceMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $prospect_source_id
 * @property string $last_source_observation_id
 * @property MembershipState $state
 * @property Carbon $last_observed_at
 * @property Carbon|null $expired_at
 * @property ProspectSource $source
 */
#[Fillable([
    'public_id',
    'workspace_id',
    'prospect_source_id',
    'person_id',
    'first_source_run_id',
    'first_source_observation_id',
    'last_source_run_id',
    'last_source_observation_id',
    'state',
    'first_observed_at',
    'last_observed_at',
    'expired_at',
])]
class PersonSourceMembership extends Model
{
    /** @use HasFactory<PersonSourceMembershipFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'state' => MembershipState::class,
            'first_observed_at' => 'datetime',
            'last_observed_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ProspectSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ProspectSource::class, 'prospect_source_id');
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<SourceRun, $this> */
    public function firstRun(): BelongsTo
    {
        return $this->belongsTo(SourceRun::class, 'first_source_run_id');
    }

    /** @return BelongsTo<SourceRun, $this> */
    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(SourceRun::class, 'last_source_run_id');
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
