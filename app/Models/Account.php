<?php

namespace App\Models;

use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string|null $website
 * @property string|null $domain_normalized
 * @property string|null $notes
 * @property array<int, string> $tags
 * @property string $source
 * @property int|null $created_by_user_id
 */
#[Fillable([
    'workspace_id',
    'name',
    'website',
    'domain_normalized',
    'notes',
    'tags',
    'source',
    'created_by_user_id',
])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_CAMPAIGN_BACKFILL = 'campaign_backfill';

    protected $attributes = [
        'source' => self::SOURCE_MANUAL,
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
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
     * @return HasMany<CampaignRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /**
     * @return HasMany<Person, $this>
     */
    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<AccountSourceMembership, $this> */
    public function sourceMemberships(): HasMany
    {
        return $this->hasMany(AccountSourceMembership::class);
    }

    /** @return HasMany<SourceObservation, $this> */
    public function sourceObservations(): HasMany
    {
        return $this->hasMany(SourceObservation::class);
    }

    /** @return HasMany<ExternalIdentity, $this> */
    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    /** @return HasMany<CanonicalFieldProvenance, $this> */
    public function fieldProvenance(): HasMany
    {
        return $this->hasMany(CanonicalFieldProvenance::class);
    }

    /** @return HasMany<AccountResearchRun, $this> */
    public function researchRuns(): HasMany
    {
        return $this->hasMany(AccountResearchRun::class);
    }

    /** @return HasMany<ResearchSourceSnapshot, $this> */
    public function researchSourceSnapshots(): HasMany
    {
        return $this->hasMany(ResearchSourceSnapshot::class);
    }

    /** @return HasMany<ResearchClaim, $this> */
    public function researchClaims(): HasMany
    {
        return $this->hasMany(ResearchClaim::class);
    }

    /** @return HasMany<QualificationDecision, $this> */
    public function qualificationDecisions(): HasMany
    {
        return $this->hasMany(QualificationDecision::class);
    }

    /** @return HasMany<QualificationReviewEvent, $this> */
    public function qualificationReviewEvents(): HasMany
    {
        return $this->hasMany(QualificationReviewEvent::class);
    }

    /** @return HasMany<ReasonToContactVersion, $this> */
    public function reasonsToContact(): HasMany
    {
        return $this->hasMany(ReasonToContactVersion::class);
    }
}
