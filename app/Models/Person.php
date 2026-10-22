<?php

namespace App\Models;

use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $account_id
 * @property string|null $full_name
 * @property string|null $first_name
 * @property string $email_address
 * @property string $email_normalized
 * @property string|null $job_title
 * @property string|null $notes
 * @property array<int, string> $tags
 * @property string $source
 * @property int|null $created_by_user_id
 */
#[Fillable([
    'workspace_id',
    'account_id',
    'full_name',
    'first_name',
    'email_address',
    'email_normalized',
    'job_title',
    'notes',
    'tags',
    'source',
    'created_by_user_id',
])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
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
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<CampaignRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<PersonSourceMembership, $this> */
    public function sourceMemberships(): HasMany
    {
        return $this->hasMany(PersonSourceMembership::class);
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
}
