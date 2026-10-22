<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Services\UniboxInbox;
use App\Models\Thread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists inbox threads for the authenticated workspace. Filter by folder (needs for attention, all_open, leads, and other Unibox folders), classification, mailbox_id, or campaign. Defaults to 20 results (maximum 100). Does not include message bodies. Does not mutate data.')]
#[IsReadOnly]
class ListThreads extends OutbossTool
{
    public function __construct(private UniboxInbox $inbox) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'folder' => $schema->string()
                ->enum(UniboxInbox::FOLDERS)
                ->description('Unibox folder. Use needs for threads that require attention. Defaults to all_open.'),
            'classification' => $schema->string()
                ->enum(ThreadClassification::class)
                ->description('If set, only threads with this classification are returned.'),
            'mailbox_id' => $schema->integer()
                ->description('If set, only threads from this mailbox are returned.'),
            'campaign' => $schema->string()
                ->description('Campaign id, all, or none (threads with no campaign recipient). Defaults to all.'),
            ...$this->paginationSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::InboxRead->value)) {
            return $denied;
        }

        $workspace = $this->actor($request)->currentWorkspace();
        $folder = $this->inbox->normalizeFolder($request->get('folder'));
        $mailboxId = $request->integer('mailbox_id') ?: null;
        $campaign = $this->inbox->campaignFilter($request->get('campaign'), $workspace);
        $classification = $request->get('classification');

        $threads = Thread::query()
            ->inWorkspace($workspace->id)
            ->tap(fn (Builder $query) => $this->inbox->applyFolder($query, $folder))
            ->tap(fn (Builder $query) => $this->inbox->applyCampaignAndMailbox($query, $mailboxId, $campaign))
            ->when(
                is_string($classification) && $classification !== '',
                function (Builder $query) use ($classification): void {
                    $enum = ThreadClassification::tryFrom($classification);

                    if ($enum !== null) {
                        $query->where('classification', $enum);
                    }
                },
            )
            ->withExists(['messages as has_inbound' => fn (Builder $messages) => $messages
                ->where('direction', MessageDirection::Inbound)
                ->where('workspace_id', $workspace->id)
                ->whereColumn('messages.mailbox_id', 'threads.mailbox_id')])
            ->with([
                'mailbox' => fn ($query) => $query->where('workspace_id', $workspace->id),
                'recipient' => fn ($query) => $query->where('workspace_id', $workspace->id),
                'recipient.campaign',
                'recipient.person' => fn ($query) => $query->where('workspace_id', $workspace->id),
                'recipient.account' => fn ($query) => $query->where('workspace_id', $workspace->id),
                'latestSentMessage' => fn ($query) => $query->where('workspace_id', $workspace->id),
            ])
            ->orderByDesc('last_message_at')
            ->paginate($this->perPage($request), ['*'], 'page', $this->page($request));

        $fallbackPeople = $this->inbox->peopleByParticipantEmail($workspace->id, $threads->getCollection());
        $summaries = $threads->getCollection()
            ->map(fn (Thread $thread) => $this->inbox->threadSummary($thread, $fallbackPeople))
            ->values()
            ->all();

        return $this->json($this->pagePayload(
            $summaries,
            $threads->currentPage(),
            $threads->perPage(),
            $threads->total(),
        ));
    }
}
