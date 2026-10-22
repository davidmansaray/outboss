<?php

namespace App\Models;

use App\Domain\Intelligence\Enums\QualificationOutcome;
use App\Domain\Intelligence\Enums\QualificationReviewState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $id
 * @property int $account_id
 * @property QualificationOutcome $outcome
 * @property QualificationReviewState $review_state
 * @property string $confidence
 * @property string $content_hash
 */
#[Fillable([
    'id', 'workspace_id', 'account_id', 'research_run_id', 'rubric_version_id', 'gtm_profile_version_id',
    'offer_version_id', 'prior_decision_id', 'input_hash', 'outcome', 'confidence', 'calibration_band',
    'missing_facts', 'disqualifiers', 'explanation', 'model_version', 'prompt_version', 'policy_version',
    'catalogue_version', 'provider_operation_id', 'provider_cost_micros', 'review_state', 'content_hash',
    'generated_at', 'reviewed_at', 'corrected_at',
])]
class QualificationDecision extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (self $decision): void {
            $mutable = ['review_state', 'reviewed_at', 'corrected_at', 'updated_at'];
            $unexpected = array_diff(array_keys($decision->getDirty()), $mutable);
            if ($unexpected !== []) {
                throw new LogicException('Qualification decisions are immutable; append a successor decision.');
            }
        });
        static::deleting(function (): never {
            throw new LogicException('Qualification decisions are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'outcome' => QualificationOutcome::class,
            'review_state' => QualificationReviewState::class,
            'missing_facts' => 'array',
            'disqualifiers' => 'array',
            'confidence' => 'decimal:4',
            'provider_cost_micros' => 'integer',
            'generated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'corrected_at' => 'datetime',
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

    /** @return BelongsTo<AccountResearchRun, $this> */
    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(AccountResearchRun::class, 'research_run_id');
    }

    /** @return BelongsTo<QualificationRubricVersion, $this> */
    public function rubricVersion(): BelongsTo
    {
        return $this->belongsTo(QualificationRubricVersion::class, 'rubric_version_id');
    }

    /** @return BelongsTo<QualificationDecision, $this> */
    public function priorDecision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prior_decision_id');
    }

    /** @return HasMany<QualificationDecisionClaim, $this> */
    public function claimLinks(): HasMany
    {
        return $this->hasMany(QualificationDecisionClaim::class, 'decision_id');
    }

    /** @return HasMany<ReasonToContactVersion, $this> */
    public function reasonsToContact(): HasMany
    {
        return $this->hasMany(ReasonToContactVersion::class, 'qualification_decision_id');
    }

    /** @return HasMany<QualificationReviewEvent, $this> */
    public function reviewEvents(): HasMany
    {
        return $this->hasMany(QualificationReviewEvent::class, 'decision_id');
    }
}
