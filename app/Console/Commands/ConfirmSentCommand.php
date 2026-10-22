<?php

namespace App\Console\Commands;

use App\Domain\Mail\Enums\SendResolution;
use App\Domain\Mail\Services\ResolveManualReviewSend;
use App\Models\SendAttempt;
use Illuminate\Console\Command;

class ConfirmSentCommand extends Command
{
    protected $signature = 'outboss:sends:confirm-sent {attempt} {--operator=} {--evidence=}';

    protected $description = 'Confirm a manual-review send as accepted by the provider';

    public function handle(ResolveManualReviewSend $resolve): int
    {
        $attempt = SendAttempt::query()->findOrFail($this->argument('attempt'));
        $resolve->handle(
            $attempt,
            SendResolution::ConfirmedSent,
            (string) $this->option('operator'),
            (string) $this->option('evidence'),
        );
        $this->info('Attempt '.$attempt->id.' confirmed sent.');

        return self::SUCCESS;
    }
}
