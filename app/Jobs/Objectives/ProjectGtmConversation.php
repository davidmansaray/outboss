<?php

namespace App\Jobs\Objectives;

use App\Domain\Objectives\Services\ProjectGtmConversation as ProjectGtmConversationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProjectGtmConversation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $threadId,
        public readonly int $messageId,
    ) {
        $this->onQueue((string) config('objectives.queue', 'objectives'));
    }

    public function uniqueId(): string
    {
        return "objective-conversation:{$this->workspaceId}:{$this->threadId}:{$this->messageId}";
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(ProjectGtmConversationService $service): void
    {
        $service->handle($this->workspaceId, $this->threadId, $this->messageId);
    }
}
