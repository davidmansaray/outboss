<?php

namespace App\Jobs\Campaigns;

use App\Domain\Campaigns\Services\ScheduleDueCampaignSends as ScheduleDueCampaignSendsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScheduleDueCampaignSends implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('campaigns');
    }

    public function handle(ScheduleDueCampaignSendsService $scheduler): void
    {
        $scheduler->handle();
    }
}
