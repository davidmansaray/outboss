<?php

namespace App\Models;

use App\Domain\Campaigns\Enums\ImportSource;
use App\Domain\Campaigns\Enums\ImportStatus;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use Database\Factories\RecipientImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'campaign_id',
    'user_id',
    'workspace_id',
    'source',
    'original_filename',
    'private_path',
    'source_hash',
    'headers',
    'mapping',
    'mapping_version',
    'status',
    'total',
    'ready',
    'imported',
    'invalid',
    'duplicate',
    'suppressed',
    'error',
    'expires_at',
])]
class RecipientImport extends Model
{
    /** @use HasFactory<RecipientImportFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $import): void {
            app(GovernedCampaignInputGuard::class)->assertRecipientImportMutable($import);

            // Updating an existing review (mapping, counters, or expiry) is
            // harmless. Creating it, or moving it to another campaign, must
            // not target a frozen experiment cohort.
            if ($import->exists && ! $import->isDirty('campaign_id')) {
                return;
            }

            $campaignId = $import->getAttribute('campaign_id');
            if (is_numeric($campaignId)) {
                app(FrozenMailExperimentInputGuard::class)
                    ->assertCampaignAudienceMutable((int) $campaignId);
            }
        });

        static::deleting(function (self $import): void {
            app(GovernedCampaignInputGuard::class)->assertRecipientImportMutable($import);
        });
    }

    protected function casts(): array
    {
        return [
            'source' => ImportSource::class,
            'status' => ImportStatus::class,
            'headers' => 'array',
            'mapping' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(RecipientImportRow::class);
    }
}
