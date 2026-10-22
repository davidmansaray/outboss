<?php

namespace App\Models;

use App\Domain\Prospecting\Enums\ObservationDisposition;
use App\Domain\Prospecting\Enums\ObservationEntityType;
use Database\Factories\SourceObservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id',
    'workspace_id',
    'prospect_source_id',
    'prospect_source_version_id',
    'source_run_id',
    'row_number',
    'input_key',
    'observation_key',
    'entity_type',
    'observed_values',
    'observed_values_hash',
    'raw_input_hash',
    'provider_record_id',
    'provider_snapshot_id',
    'canonical_url',
    'provider_observed_at',
    'retrieved_at',
    'disposition',
    'account_id',
    'person_id',
    'match_method',
    'match_confidence',
    'policy_classification',
    'policy_hash',
    'retain_until',
    'display_classification',
    'export_classification',
    'safe_error_code',
    'safe_error_message',
    'expired_at',
    'redacted_at',
])]
class SourceObservation extends Model
{
    /** @use HasFactory<SourceObservationFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'entity_type' => ObservationEntityType::class,
            'disposition' => ObservationDisposition::class,
            'observed_values' => 'array',
            'provider_observed_at' => 'datetime',
            'retrieved_at' => 'datetime',
            'retain_until' => 'datetime',
            'expired_at' => 'datetime',
            'redacted_at' => 'datetime',
            'row_number' => 'integer',
            'match_confidence' => 'decimal:4',
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

    /** @return BelongsTo<ProspectSourceVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProspectSourceVersion::class, 'prospect_source_version_id');
    }

    /** @return BelongsTo<SourceRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SourceRun::class, 'source_run_id');
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
}
