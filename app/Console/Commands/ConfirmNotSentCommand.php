<?php

namespace App\Console\Commands;

use App\Domain\Mail\Enums\SendResolution;
use App\Domain\Mail\Services\ResolveManualReviewSend;
use App\Models\SendAttempt;
use Illuminate\Console\Command;

class ConfirmNotSentCommand extends Command
{
    protected $signature = 'outboss:sends:confirm-not-sent {attempt} {--operator=} {--evidence=}';

    protected $description = 'Prove a manual-review send was not accepted and allow a safe retry';

    public function handle(ResolveManualReviewSend $resolve): int
    {
        $attempt = SendAttempt::query()->findOrFail($this->argument('attempt'));
        $resolve->handle(
            $attempt,
            SendResolution::ProvenNotSent,
            (string) $this->option('operator'),
            (string) $this->option('evidence'),
        );
        $this->info('Attempt '.$attempt->id.' marked not sent.');

        return self::SUCCESS;
    }
}
