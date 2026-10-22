<?php

namespace App\Domain\Mail\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Devices\Exceptions\DeviceAuthException;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Person;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class UniboxInbox
{
    public const FOLDERS = ['leads', 'all_replies', 'closed', 'needs', 'all_open', 'open', 'ooo', 'delivery', 'opt_outs', 'problems', 'done', 'sent'];

    public function campaignFilter(mixed $raw, Workspace $workspace): string
    {
        if (! is_string($raw) || $raw === '' || $raw === 'all') {
            return 'all';
        }

        if ($raw === 'none') {
            return 'none';
        }

        Campaign::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey((int) $raw)
            ->firstOrFail();

        return (string) (int) $raw;
    }

    public function normalizeFolder(mixed $folder): string
    {
        return is_string($folder) && in_array($folder, self::FOLDERS, true)
            ? $folder
            : 'all_open';
    }

    /**
     * @param  Builder<Thread>  $query
     */
    public function applyFolder(Builder $query, string $folder): void
    {
        if ($folder === 'sent') {
            $query->visibleInSent();

            return;
        }

        if ($folder === 'leads') {
            $query
                ->visibleInInbox()
                ->where('classification', ThreadClassification::Human)
                ->where(function (Builder $leads) {
                    $leads
                        ->where('local_state', '!=', ThreadLocalState::Done)
                        ->orWhereHas('recipient', fn (Builder $recipient) => $recipient
                            ->where('status', RecipientStatus::Interested));
                });

            return;
        }

        if ($folder === 'all_replies') {
            $query
                ->visibleInInbox()
                ->where('classification', ThreadClassification::Human);

            return;
        }

        if ($folder === 'closed') {
            $query
                ->visibleInInbox()
                ->where('local_state', ThreadLocalState::Done);

            return;
        }

        if ($folder === 'delivery') {
            $query
                ->visibleInInbox()
                ->where('local_state', ThreadLocalState::Problem)
                ->where('classification', '!=', ThreadClassification::Unsubscribe);

            return;
        }

        if ($folder === 'opt_outs') {
            $query
                ->visibleInInbox()
                ->where('local_state', ThreadLocalState::Problem)
                ->where('classification', ThreadClassification::Unsubscribe);

            return;
        }

        $state = ThreadLocalState::fromFolder($folder);

        $query
            ->visibleInInbox()
            ->when($state, fn (Builder $scoped) => $scoped->where('local_state', $state))
            ->when($folder === 'all_open', fn (Builder $scoped) => $scoped->where('local_state', '!=', ThreadLocalState::Done));
    }

    /**
     * @param  Builder<Thread>  $query
     */
    public function applyCampaignAndMailbox(Builder $query, ?int $mailboxId, string $campaign): void
    {
        $query->when($mailboxId, fn (Builder $scoped) => $scoped->where('mailbox_id', $mailboxId));

        if ($campaign === 'none') {
            $query->whereNull('campaign_recipient_id');

            return;
        }

        if ($campaign !== 'all') {
            $query->whereHas('recipient', fn (Builder $recipient) => $recipient->where('campaign_id', (int) $campaign));
        }
    }

    /**
     * The thread list query shared by the web Unibox, the API and the changes
     * feed: workspace isolation plus everything threadSummary() reads.
     *
     * @return Builder<Thread>
     */
    public function listQuery(Workspace $workspace): Builder
    {
        return Thread::query()
            ->inWorkspace($workspace->id)
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
            ]);
    }

    /**
     * Match subject, preview, or the other person's name or email address.
     *
     * @param  Builder<Thread>  $query
     */
    public function applySearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if (mb_strlen($term) < 2) {
            return;
        }

        // "!" escapes LIKE wildcards the same way on MySQL and SQLite.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(mb_substr($term, 0, 100))).'%';

        $query->where(function (Builder $search) use ($like): void {
            foreach (['threads.subject', 'threads.snippet', 'threads.participant_name', 'threads.participant_email'] as $column) {
                $search->orWhereRaw("lower({$column}) like ? escape '!'", [$like]);
            }
        });
    }

    /**
     * @param  Builder<Thread>  $query
     */
    public function applyUnread(Builder $query, bool $unreadOnly): void
    {
        $query->when($unreadOnly, fn (Builder $scoped) => $scoped->where('threads.is_unread', true));
    }

    /**
     * Keyset page ordered newest first. Unlike page numbers, the cursor stays
     * correct while new replies are inserted at the top of the list.
     *
     * @param  Builder<Thread>  $query
     * @return array{items: Collection<int, Thread>, next_cursor: string|null}
     */
    public function cursorPage(Builder $query, ?string $cursor, int $perPage): array
    {
        $position = $cursor === null || $cursor === '' ? null : $this->decodeCursor($cursor);

        if ($position !== null) {
            $query->where(function (Builder $after) use ($position): void {
                if ($position['t'] === null) {
                    $after->whereNull('threads.last_message_at')->where('threads.id', '<', $position['id']);

                    return;
                }

                $after
                    ->where('threads.last_message_at', '<', $position['t'])
                    ->orWhere(fn (Builder $tie) => $tie
                        ->where('threads.last_message_at', $position['t'])
                        ->where('threads.id', '<', $position['id']))
                    ->orWhereNull('threads.last_message_at');
            });
        }

        $rows = $query
            ->orderByRaw('threads.last_message_at is null')
            ->orderByDesc('threads.last_message_at')
            ->orderByDesc('threads.id')
            ->limit($perPage + 1)
            ->get();

        $items = $rows->take($perPage)->values();
        $last = $items->last();
        $next = $rows->count() > $perPage && $last instanceof Thread
            ? $this->encode([
                't' => $this->dbTimestamp($last->getAttribute('last_message_at')),
                'id' => (int) $last->id,
            ])
            : null;

        return ['items' => $items, 'next_cursor' => $next];
    }

    /**
     * Folder totals for the Unibox sidebar and the iOS folder list.
     *
     * @return array<string, int>
     */
    public function counts(Workspace $workspace, ?int $mailboxId, string $campaign): array
    {
        $scoped = fn (): Builder => Thread::query()
            ->inWorkspace($workspace->id)
            ->tap(fn (Builder $query) => $this->applyCampaignAndMailbox($query, $mailboxId, $campaign));

        $byState = $scoped()
            ->visibleInInbox()
            ->selectRaw('local_state, count(*) as aggregate')
            ->groupBy('local_state')
            ->pluck('aggregate', 'local_state');
        $problemByClassification = $scoped()
            ->visibleInInbox()
            ->where('local_state', ThreadLocalState::Problem)
            ->selectRaw('classification, count(*) as aggregate')
            ->groupBy('classification')
            ->pluck('aggregate', 'classification');
        $optOuts = (int) ($problemByClassification[ThreadClassification::Unsubscribe->value] ?? 0);
        $problems = (int) ($byState[ThreadLocalState::Problem->value] ?? 0);
        $done = (int) ($byState[ThreadLocalState::Done->value] ?? 0);

        return [
            'leads' => $scoped()->tap(fn (Builder $query) => $this->applyFolder($query, 'leads'))->count(),
            'all_replies' => $scoped()->tap(fn (Builder $query) => $this->applyFolder($query, 'all_replies'))->count(),
            'closed' => $done,
            'needs' => (int) ($byState[ThreadLocalState::NeedsAction->value] ?? 0),
            'open' => (int) ($byState[ThreadLocalState::Open->value] ?? 0),
            'ooo' => (int) ($byState[ThreadLocalState::OutOfOffice->value] ?? 0),
            'delivery' => $problems - $optOuts,
            'opt_outs' => $optOuts,
            'problems' => $problems,
            'done' => $done,
            'all_open' => $scoped()->tap(fn (Builder $query) => $this->applyFolder($query, 'all_open'))->count(),
            'sent' => $scoped()->tap(fn (Builder $query) => $this->applyFolder($query, 'sent'))->count(),
        ];
    }

    /**
     * Unread totals for the folders the iOS app badges.
     *
     * @return array{needs: int, leads: int, all_open: int}
     */
    public function unreadCounts(Workspace $workspace, ?int $mailboxId, string $campaign): array
    {
        $unread = fn (string $folder): int => Thread::query()
            ->inWorkspace($workspace->id)
            ->tap(fn (Builder $query) => $this->applyCampaignAndMailbox($query, $mailboxId, $campaign))
            ->tap(fn (Builder $query) => $this->applyFolder($query, $folder))
            ->where('threads.is_unread', true)
            ->count();

        return [
            'needs' => $unread('needs'),
            'leads' => $unread('leads'),
            'all_open' => $unread('all_open'),
        ];
    }

    /**
     * The app icon badge: unread Needs reply threads across every workspace
     * the person belongs to, because the badge belongs to the phone.
     */
    public function badgeFor(User $user): int
    {
        return (int) $user->workspaces()->pluck('workspaces.id')->sum(
            fn (int|string $workspaceId): int => Thread::query()
                ->inWorkspace((int) $workspaceId)
                ->tap(fn (Builder $query) => $this->applyFolder($query, 'needs'))
                ->where('threads.is_unread', true)
                ->count(),
        );
    }

    /**
     * Threads changed since a watermark, oldest change first. A caught-up
     * batch hands back a "tail" watermark that re-reads a short overlap, so a
     * transaction that committed late is never missed; clients drop rows they
     * already hold with the same updated_at.
     *
     * @return array{rows: list<array<string, mixed>>, next_since: string, has_more: bool}
     */
    public function changes(Workspace $workspace, ?string $since, int $limit): array
    {
        if ($since === null || $since === '') {
            return [
                'rows' => [],
                'next_since' => $this->encode(['t' => $this->dbTimestamp(now()), 'id' => 0, 'm' => 'tail']),
                'has_more' => false,
            ];
        }

        $position = $this->decodeWatermark($since);
        $queriedAt = now();
        $query = Thread::query()->inWorkspace($workspace->id);

        if ($position['m'] === 'page') {
            $query->where(fn (Builder $after) => $after
                ->where('threads.updated_at', '>', $position['t'])
                ->orWhere(fn (Builder $tie) => $tie
                    ->where('threads.updated_at', $position['t'])
                    ->where('threads.id', '>', $position['id'])));
        } else {
            $overlap = (int) config('outboss.mobile.changes_overlap_seconds', 10);
            $query->where('threads.updated_at', '>=', $this->dbTimestamp(
                Carbon::parse($position['t'])->subSeconds($overlap),
            ));
        }

        $changed = $query
            ->orderBy('threads.updated_at')
            ->orderBy('threads.id')
            ->limit($limit + 1)
            ->get(['threads.id', 'threads.updated_at']);
        $hasMore = $changed->count() > $limit;
        $changed = $changed->take($limit)->values();
        $ids = $changed->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $visibleIds = $ids === [] ? [] : Thread::query()
            ->whereIn('threads.id', $ids)
            ->where(fn (Builder $visible) => $visible
                ->visibleInInbox()
                ->orWhere(fn (Builder $sent) => $sent->visibleInSent()))
            ->pluck('threads.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $visible = $visibleIds === [] ? collect() : $this->listQuery($workspace)
            ->whereIn('threads.id', $visibleIds)
            ->get()
            ->keyBy('id');
        $fallbackPeople = $this->peopleByParticipantEmail($workspace->id, $visible->values());

        $rows = $changed->values()->map(function (Thread $row) use ($visible, $fallbackPeople): array {
            $thread = $visible->get($row->id);

            return $thread instanceof Thread
                ? [...$this->threadSummary($thread, $fallbackPeople), 'visible' => true]
                : [
                    'id' => $row->id,
                    'visible' => false,
                    'updated_at' => $this->iso8601($row->getAttribute('updated_at')),
                ];
        })->all();

        // Mid-batch, continue strictly after the last row. Once caught up,
        // remember when this query ran; the next call re-reads only the
        // overlap before that moment.
        $last = $changed->last();
        $next = $hasMore && $last instanceof Thread
            ? ['t' => $this->dbTimestamp($last->getAttribute('updated_at')), 'id' => (int) $last->id, 'm' => 'page']
            : ['t' => $this->dbTimestamp($queriedAt), 'id' => 0, 'm' => 'tail'];

        return [
            'rows' => array_values($rows),
            'next_since' => $this->encode($next),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array{t: string|null, id: int}
     */
    private function decodeCursor(string $cursor): array
    {
        $decoded = $this->decode($cursor);

        if (! is_array($decoded)
            || ! array_key_exists('t', $decoded)
            || ! (is_string($decoded['t']) || $decoded['t'] === null)
            || ! is_int($decoded['id'] ?? null)) {
            throw ValidationException::withMessages(['cursor' => 'This cursor is invalid. Load the list from the start.']);
        }

        return ['t' => $decoded['t'], 'id' => $decoded['id']];
    }

    /**
     * @return array{t: string, id: int, m: string}
     */
    private function decodeWatermark(string $since): array
    {
        $decoded = $this->decode($since);

        if (! is_array($decoded)
            || ! is_string($decoded['t'] ?? null)
            || ! is_int($decoded['id'] ?? null)
            || ! in_array($decoded['m'] ?? null, ['tail', 'page'], true)) {
            throw DeviceAuthException::syncResetRequired();
        }

        try {
            $at = Carbon::parse($decoded['t']);
        } catch (\Throwable) {
            throw DeviceAuthException::syncResetRequired();
        }

        if ($at->lt(now()->subDays((int) config('outboss.mobile.changes_retention_days', 30)))) {
            throw DeviceAuthException::syncResetRequired();
        }

        return ['t' => $decoded['t'], 'id' => $decoded['id'], 'm' => $decoded['m']];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }

    private function decode(string $value): mixed
    {
        $json = base64_decode(strtr($value, '-_', '+/'), true);

        return $json === false ? null : json_decode($json, true);
    }

    private function dbTimestamp(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface
            // Matches how Eloquent writes timestamps (app timezone, seconds).
            ? Carbon::instance($value)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s')
            : null;
    }

    private function iso8601(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }

    /**
     * @param  Collection<int, Thread>  $threads
     * @return array<string, Person>
     */
    public function peopleByParticipantEmail(int $workspaceId, Collection $threads): array
    {
        $emails = [];

        foreach ($threads as $thread) {
            $recipient = $this->workspaceRecipient($thread);
            $linked = $recipient?->person;

            if ($linked instanceof Person
                && (int) $linked->getAttribute('workspace_id') === $workspaceId) {
                continue;
            }

            $email = EmailNormalizer::normalize($thread->participant_email);

            if (EmailNormalizer::isValid($email)) {
                $emails[] = $email;
            }
        }

        $emails = array_values(array_unique($emails));

        if ($emails === []) {
            return [];
        }

        $grouped = Person::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('email_normalized', $emails)
            ->with(['account' => fn ($query) => $query->where('workspace_id', $workspaceId)])
            ->get()
            ->groupBy('email_normalized');

        $matches = [];

        foreach ($grouped as $email => $people) {
            if ($people->count() === 1) {
                $matches[(string) $email] = $people->first();
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, Person>  $fallbackPeople
     * @return array<string, mixed>
     */
    public function threadSummary(Thread $thread, array $fallbackPeople = []): array
    {
        $recipient = $this->workspaceRecipient($thread);
        $campaign = $recipient === null
            ? null
            : Campaign::query()->whereKey((int) $recipient->getAttribute('campaign_id'))->first();
        $recipientStatus = $recipient?->getAttribute('status');
        $lastMessageAt = $thread->getAttribute('last_message_at');
        $mailbox = $thread->mailbox;
        $classification = $thread->getAttribute('classification');
        $state = $thread->getAttribute('local_state');
        [$person, $account] = $this->linkedRecords($thread, $recipient, $fallbackPeople);

        return [
            'id' => $thread->id,
            'subject' => $thread->subject,
            'from' => $this->correspondent($thread),
            'participant_email' => $thread->participant_email,
            'snippet' => $thread->snippet,
            'mailbox' => $mailbox instanceof Mailbox ? $mailbox->email_address : null,
            'mailbox_id' => (int) $thread->getAttribute('mailbox_id'),
            'participant_name' => $thread->participant_name,
            'unread' => $thread->is_unread,
            'has_inbound' => (bool) $thread->getAttribute('has_inbound'),
            'classification' => $classification instanceof ThreadClassification
                ? $classification->value
                : (is_string($classification) ? $classification : null),
            'state' => $state instanceof ThreadLocalState
                ? $state->value
                : (is_string($state) ? $state : null),
            'campaign_recipient_id' => $recipient?->getKey(),
            'campaign_id' => $recipient?->getAttribute('campaign_id'),
            'campaign_name' => $campaign?->name,
            'person' => $person,
            'account' => $account,
            'recipient_status' => $recipientStatus instanceof RecipientStatus
                ? $recipientStatus->value
                : null,
            'last_message_at' => $lastMessageAt instanceof CarbonInterface
                ? $lastMessageAt->toIso8601String()
                : null,
            'updated_at' => $this->iso8601($thread->getAttribute('updated_at')),
        ];
    }

    public function workspaceRecipient(Thread $thread): ?CampaignRecipient
    {
        $recipientId = $thread->getAttribute('campaign_recipient_id');
        $recipient = $recipientId === null
            ? null
            : CampaignRecipient::query()->whereKey((int) $recipientId)->first();
        if ($recipient === null
            || (int) $recipient->getAttribute('workspace_id') !== (int) $thread->getAttribute('workspace_id')
            || (int) $recipient->getAttribute('mailbox_id') !== (int) $thread->getAttribute('mailbox_id')) {
            return null;
        }

        $campaign = Campaign::query()->whereKey((int) $recipient->getAttribute('campaign_id'))->first();

        return $campaign !== null
            && (int) $campaign->getAttribute('workspace_id') === (int) $thread->getAttribute('workspace_id')
            ? $recipient
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function messagePayload(Message $message): array
    {
        $direction = $message->getAttribute('direction');
        $receivedAt = $message->getAttribute('received_at');
        $sentAt = $message->getAttribute('sent_at');

        return [
            'id' => $message->id,
            'from' => $message->from_name ?: $message->from_email,
            'from_name' => $message->from_name,
            'from_email' => $message->from_email,
            'to' => $this->addressList($message->getAttribute('to_recipients')),
            'cc' => $this->addressList($message->getAttribute('cc_recipients')),
            'subject' => $message->subject,
            'has_attachments' => (bool) $message->getAttribute('has_attachments'),
            'direction' => $direction instanceof MessageDirection
                ? $direction->value
                : (is_string($direction) ? $direction : null),
            'body' => $message->body_text,
            'html' => $message->body_html_sanitized,
            'at' => $receivedAt instanceof CarbonInterface
                ? $receivedAt->toIso8601String()
                : ($sentAt instanceof CarbonInterface ? $sentAt->toIso8601String() : null),
        ];
    }

    /**
     * @return list<array{email: string, name: string|null}>
     */
    private function addressList(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $addresses = [];

        foreach ($stored as $entry) {
            $email = is_array($entry) ? ($entry['email'] ?? null) : $entry;

            if (! is_string($email) || trim($email) === '') {
                continue;
            }

            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            $addresses[] = [
                'email' => trim($email),
                'name' => is_string($name) && trim($name) !== '' ? trim($name) : null,
            ];
        }

        return $addresses;
    }

    private function correspondent(Thread $thread): ?string
    {
        if ((bool) $thread->getAttribute('has_inbound')) {
            return $thread->participant_name ?: $thread->participant_email;
        }

        $latestSentMessage = $thread->latestSentMessage;
        if ($latestSentMessage !== null
            && ((int) $latestSentMessage->getAttribute('workspace_id') !== (int) $thread->getAttribute('workspace_id')
                || (int) $latestSentMessage->getAttribute('mailbox_id') !== (int) $thread->getAttribute('mailbox_id'))) {
            $latestSentMessage = null;
        }

        foreach ($latestSentMessage?->outboundRecipients() ?? [] as $recipient) {
            $email = EmailNormalizer::normalize($recipient['email']);

            if (! EmailNormalizer::isValid($email)) {
                continue;
            }

            $name = $recipient['name'] ?? null;

            return is_string($name) && trim($name) !== '' ? trim($name) : $email;
        }

        return $thread->participant_name ?: $thread->participant_email;
    }

    /**
     * @param  array<string, Person>  $fallbackPeople
     * @return array{0: array{id: int, name: string, title: string|null}|null, 1: array{id: int, name: string}|null}
     */
    private function linkedRecords(Thread $thread, ?CampaignRecipient $recipient, array $fallbackPeople): array
    {
        $workspaceId = (int) $thread->getAttribute('workspace_id');
        $person = null;
        $account = null;

        if ($recipient) {
            $personModel = $recipient->relationLoaded('person')
                ? $recipient->person
                : $recipient->person()->where('workspace_id', $workspaceId)->first();
            $accountModel = $recipient->relationLoaded('account')
                ? $recipient->account
                : $recipient->account()->where('workspace_id', $workspaceId)->first();

            if ($personModel instanceof Person
                && (int) $personModel->getAttribute('workspace_id') === $workspaceId) {
                $person = $this->personSummary($personModel);
            }

            if ($accountModel instanceof Account
                && (int) $accountModel->getAttribute('workspace_id') === $workspaceId) {
                $account = [
                    'id' => $accountModel->id,
                    'name' => $accountModel->name,
                ];
            }
        }

        if ($person === null) {
            $email = EmailNormalizer::normalize($thread->participant_email);
            $fallback = $fallbackPeople[$email] ?? null;

            if ($fallback instanceof Person
                && (int) $fallback->getAttribute('workspace_id') === $workspaceId) {
                $person = $this->personSummary($fallback);

                if ($account === null) {
                    $fallbackAccount = $fallback->account;
                    if ($fallbackAccount instanceof Account
                        && (int) $fallbackAccount->getAttribute('workspace_id') === $workspaceId) {
                        $account = [
                            'id' => $fallbackAccount->id,
                            'name' => $fallbackAccount->name,
                        ];
                    }
                }
            }
        }

        return [$person, $account];
    }

    /**
     * @return array{id: int, name: string, title: string|null}
     */
    private function personSummary(Person $person): array
    {
        $title = trim((string) $person->job_title);

        return [
            'id' => $person->id,
            'name' => $person->full_name ?: $person->email_address,
            'title' => $title === '' ? null : $title,
        ];
    }
}
