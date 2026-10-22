<?php

namespace App\Models;

use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Campaign mailbox relation with the same frozen-input boundary as the
 * experiment cohort mailbox relation. Ordinary campaigns remain unchanged;
 * cohort campaigns cannot replace their sender set after freeze.
 */
class CampaignMailbox extends Pivot
{
    public $incrementing = false;

    protected $table = 'campaign_mailbox';

    protected static function booted(): void
    {
        static::saving(function (self $pivot): void {
            app(GovernedCampaignInputGuard::class)->assertCampaignMailboxMutable(
                (int) $pivot->getAttribute('campaign_id'),
            );

            if (! FrozenMailExperimentInputGuard::lifecycleMutationIsTrusted()) {
                app(FrozenMailExperimentInputGuard::class)->assertCampaignMailboxMutable(
                    (int) $pivot->getAttribute('campaign_id'),
                );
            }
        });

        static::deleting(function (self $pivot): void {
            app(GovernedCampaignInputGuard::class)->assertCampaignMailboxMutable(
                (int) $pivot->getAttribute('campaign_id'),
            );

            if (! FrozenMailExperimentInputGuard::lifecycleMutationIsTrusted()) {
                app(FrozenMailExperimentInputGuard::class)->assertCampaignMailboxMutable(
                    (int) $pivot->getAttribute('campaign_id'),
                );
            }
        });
    }
}
