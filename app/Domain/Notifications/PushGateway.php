<?php

namespace App\Domain\Notifications;

interface PushGateway
{
    /**
     * @param  'production'|'sandbox'  $environment
     */
    public function send(string $deviceToken, string $environment, PushMessage $message): PushResult;
}
