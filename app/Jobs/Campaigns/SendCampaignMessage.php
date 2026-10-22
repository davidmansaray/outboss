<?php

namespace App\Jobs\Campaigns;

use App\Domain\Campaigns\Services\SendCampaignMessage as SendCampaignMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendCampaignMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $attemptId)
    {
        $this->onQueue('mail-send');
    }

    public function handle(SendCampaignMessageService $sender): void
    {
        $sender->handle($this->attemptId);
    }
}
