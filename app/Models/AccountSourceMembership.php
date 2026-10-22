<?php

namespace App\Models;

use App\Domain\Prospecting\Enums\MembershipState;
use Database\Factories\AccountSourceMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'workspace_id',
    'prospect_source_id',
    'account_id',
    'first_source_run_id',
    'first_source_observation_id',
    'last_source_run_id',
    'last_source_observation_id',
    'state',
    'first_observed_at',
    'last_observed_at',
    'expired_at',
])]
class AccountSourceMembership extends Model
{
    /** @use HasFactory<AccountSourceMembershipFactory> */
    use HasFactory;

    protected $keyType = 'int';

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

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
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
