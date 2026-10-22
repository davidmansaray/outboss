<?php

namespace App\Models;

use App\Domain\Mail\Enums\MailExperimentStatus;
use Database\Factories\MailExperimentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'workspace_id',
    'created_by_user_id',
    'name',
    'hypothesis',
    'status',
    'randomisation_seed',
    'primary_metric',
    'target_assignments_per_cohort',
    'configuration',
    'operator_minutes',
    'operator_cost_micros',
    'infrastructure_cost_micros',
    'incident_count',
    'incidents',
    'assignment_snapshot',
    'assignment_snapshot_hash',
    'frozen_at',
    'started_at',
    'completed_at',
    'aborted_at',
])]
#[Hidden(['randomisation_seed'])]
class MailExperiment extends Model
{
    /** @use HasFactory<MailExperimentFactory> */
    use HasFactory;

    /** @var list<string> */
    public const IMMUTABLE_ATTRIBUTES = [
        'workspace_id',
        'created_by_user_id',
        'name',
        'hypothesis',
        'randomisation_seed',
        'primary_metric',
        'target_assignments_per_cohort',
        'configuration',
        'assignment_snapshot',
        'assignment_snapshot_hash',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $experiment): void {
            if ($experiment->exists
                && $experiment->getOriginal('assignment_snapshot_hash') !== null
                && $experiment->isDirty(['assignment_snapshot', 'assignment_snapshot_hash'])) {
                throw new LogicException('A mail experiment assignment snapshot cannot be replaced.');
            }

            if ($experiment->exists && $experiment->isFrozen()
                && $experiment->isDirty(self::IMMUTABLE_ATTRIBUTES)) {
                throw new LogicException('A frozen mail experiment cannot change its inputs.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => MailExperimentStatus::class,
            'randomisation_seed' => 'encrypted',
            'configuration' => 'array',
            'target_assignments_per_cohort' => 'integer',
            'operator_minutes' => 'integer',
            'operator_cost_micros' => 'integer',
            'infrastructure_cost_micros' => 'integer',
            'incident_count' => 'integer',
            'incidents' => 'array',
            'assignment_snapshot' => 'array',
            'frozen_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'aborted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<MailExperimentCohort, $this> */
    public function cohorts(): HasMany
    {
        return $this->hasMany(MailExperimentCohort::class);
    }

    /** @return HasMany<MailExperimentAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(MailExperimentAssignment::class);
    }

    public function isFrozen(): bool
    {
        return $this->getAttribute('frozen_at') !== null;
    }

    public function assertMutable(): void
    {
        if ($this->isFrozen()) {
            throw new LogicException('A frozen mail experiment cannot change its inputs.');
        }
    }

    public function statusValue(): string
    {
        $status = $this->getAttribute('status');

        return $status instanceof MailExperimentStatus ? $status->value : (string) $status;
    }
}
