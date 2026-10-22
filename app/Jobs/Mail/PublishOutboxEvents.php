<?php

namespace App\Jobs\Mail;

use App\Domain\Mail\Services\PublishOutboxEvents as PublishOutboxEventsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishOutboxEvents implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('campaigns');
    }

    public function handle(PublishOutboxEventsService $publisher): void
    {
        $publisher->handle();
    }
}
