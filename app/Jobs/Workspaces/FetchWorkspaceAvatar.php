<?php

namespace App\Jobs\Workspaces;

use App\Domain\Workspaces\Services\WorkspaceFaviconFetcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class FetchWorkspaceAvatar implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $expectedWebsiteHash,
    ) {
        $this->onQueue((string) config('workspaces.avatars.queue', 'low'));
    }

    public function uniqueId(): string
    {
        return "workspace-avatar:{$this->workspaceId}:{$this->expectedWebsiteHash}";
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(WorkspaceFaviconFetcher $fetcher): void
    {
        $fetcher->refresh($this->workspaceId, $this->expectedWebsiteHash);
    }
}
