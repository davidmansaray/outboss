<?php

namespace App\Jobs\Campaigns;

use App\Domain\Campaigns\Services\ExpireRecipientImport as ExpireRecipientImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireRecipientImport implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(ExpireRecipientImportService $expire): void
    {
        $expire->handle();
    }
}
