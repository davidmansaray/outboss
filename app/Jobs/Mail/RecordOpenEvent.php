<?php

namespace App\Jobs\Mail;

use App\Domain\Mail\Services\RecordOpenEvent as RecordOpenEventService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordOpenEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 70;

    public function __construct(
        public string $token,
        public ?string $ip,
        public ?string $userAgent,
        public string $bucket,
    ) {
        $this->onQueue('low');
    }

    public function uniqueId(): string
    {
        return $this->token.':'.$this->bucket;
    }

    public function handle(RecordOpenEventService $recorder): void
    {
        $recorder->handle($this->token, $this->ip, $this->userAgent);
    }
}
