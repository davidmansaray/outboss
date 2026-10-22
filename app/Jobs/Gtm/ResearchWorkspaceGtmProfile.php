<?php

namespace App\Jobs\Gtm;

use App\Domain\Gtm\Services\RunGtmProfileResearch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/**
 * The serialized research payload is deliberately limited to aggregate IDs.
 * Website content, prompts, provider results, and credentials stay in the
 * database/service boundary and never enter a queued job payload.
 */
final class ResearchWorkspaceGtmProfile implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public int $uniqueFor;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $profileId,
        public readonly string $researchRunUuid,
        public readonly ?int $initiatingUserId,
    ) {
        $this->onQueue((string) config('gtm.queue', config('ai.queue', 'ai')));
        $this->timeout = max(60, (int) config('gtm.research.timeout_seconds', 900));
        $this->uniqueFor = $this->timeout + 60;
    }

    public function uniqueId(): string
    {
        return 'gtm-profile-research:'.$this->profileId.':'.$this->researchRunUuid;
    }

    public function backoff(): int
    {
        return max(1, (int) config('gtm.research.backoff_seconds', 30));
    }

    public function handle(RunGtmProfileResearch $research): void
    {
        $research->handle($this->profileId, $this->researchRunUuid, $this->initiatingUserId);
    }

    public function failed(?Throwable $exception): void
    {
        app(RunGtmProfileResearch::class)->failById(
            $this->profileId,
            $this->researchRunUuid,
            $exception instanceof TimeoutExceededException
                ? 'research_timed_out'
                : 'research_failed',
        );
    }
}
