<?php

namespace App\Console\Commands;

use App\Domain\Notifications\PushConfiguration;
use Illuminate\Console\Command;

class PushCheckCommand extends Command
{
    protected $signature = 'outboss:push:check';

    protected $description = 'Check whether iOS push notifications (APNs) are configured correctly';

    public function handle(PushConfiguration $push): int
    {
        $check = $push->check();

        if ($check['credentials'] === null) {
            $this->components->warn('Push notifications are off: '.$check['problem']);

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Push notifications are on for %s (key %s, team %s).',
            $check['credentials']->bundleId,
            $check['credentials']->keyId,
            $check['credentials']->teamId,
        ));

        return self::SUCCESS;
    }
}
