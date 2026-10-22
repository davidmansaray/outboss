<?php

namespace App\Mcp\Prompts;

use App\Domain\Api\TokenScope;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Services\UniboxInbox;
use App\Mcp\Concerns\ChecksScopes;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;

#[Name('triage-replies')]
#[Description('Summarise Unibox threads that need attention and suggest classification actions (interested, replied, not_now, wrong_person, or a remove action). Confirm with the user before applying ClassifyThread.')]
class TriageRepliesPrompt extends Prompt
{
    use ChecksScopes;

    public function __construct(private UniboxInbox $inbox) {}

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::InboxRead->value)) {
            return $denied;
        }

        $workspace = $this->actor($request)->currentWorkspace();
        $threads = Thread::query()
            ->inWorkspace($workspace->id)
            ->tap(fn (Builder $query) => $this->inbox->applyFolder($query, 'needs'))
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
            ->limit(20)
            ->get();

        $fallbackPeople = $this->inbox->peopleByParticipantEmail($workspace->id, $threads);
        $lines = $threads->map(function (Thread $thread) use ($fallbackPeople): string {
            $summary = $this->inbox->threadSummary($thread, $fallbackPeople);
            $from = $summary['from'] ?? $summary['participant_email'] ?? 'unknown';
            $subject = $summary['subject'] ?? '(no subject)';
            $snippet = $summary['snippet'] ?? '';
            $campaign = $summary['campaign_name'] ?? 'no campaign';
            $state = $summary['state'] ?? 'unknown';
            $classification = $summary['classification'] ?? 'unknown';

            return "- Thread {$summary['id']}: {$from} / {$subject} — {$snippet} (campaign: {$campaign}; state: {$state}; classification: {$classification})";
        })->implode("\n");

        if ($lines === '') {
            $lines = 'No threads currently need attention.';
        }

        return Response::text(<<<MARKDOWN
You are helping a user triage inbound replies in Outboss.

Summarise the threads that need attention and, for each one, suggest a ClassifyThread action:
interested, replied, treat_as_reply, not_now, wrong_person, remove_bad_address, or remove_from_campaign.

Do not call ClassifyThread or send any email until the user confirms.

Threads (up to 20):
{$lines}
MARKDOWN);
    }
}
