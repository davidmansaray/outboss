<?php

namespace App\Http\Controllers;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\ImportSource;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Exceptions\GovernedCampaignLaunchRequired;
use App\Domain\Campaigns\Services\AssignUnassignedDraftRecipients;
use App\Domain\Campaigns\Services\CampaignCompositionView;
use App\Domain\Campaigns\Services\CampaignLaunchReadiness;
use App\Domain\Campaigns\Services\CampaignOutcomeCounts;
use App\Domain\Campaigns\Services\CommitRecipientImport;
use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Domain\Campaigns\Services\MailboxSendBudget;
use App\Domain\Campaigns\Services\PrepareCampaignSendingAllowance;
use App\Domain\Campaigns\Services\PreviewRecipientImport;
use App\Domain\Campaigns\Services\PublishSequenceVersion;
use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Campaigns\Services\ValidateSequenceStructure;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Exceptions\FrozenMailExperimentInputMutation;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Http\Requests\CommitRecipientImportRequest;
use App\Http\Requests\LaunchCampaignRequest;
use App\Http\Requests\PreviewRecipientImportRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\RecipientImport;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use App\Models\Thread;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace();
        $workspaceId = (int) $workspace->id;
        $campaigns = $workspace
            ->campaigns()
            ->with([
                'draftVersion.activeSteps',
                'publishedVersion.activeSteps',
                'mailboxes' => fn ($query) => $query
                    ->where('mailboxes.workspace_id', $workspaceId)
                    ->whereHas('connection', fn ($connection) => $connection->where('workspace_id', $workspaceId)),
            ])
            ->withCount([
                'recipients as people_count' => fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('status', '!=', RecipientStatus::Removed),
                'recipients as replies_count' => fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->replied(),
                'recipients as interested_count' => fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->interested(),
                'recipients as sent_count' => fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->sent(),
                'recipients as queued_count' => fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->queued(),
                'recipients as bounced_count' => fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->bounced(),
                'recipients as unsubscribed_count' => fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->unsubscribed(),
            ])
            ->latest('updated_at')
            ->get()
            ->map(function (Campaign $campaign): array {
                $version = $campaign->draftVersion ?? $campaign->publishedVersion;
                $emails = $campaign->mailboxes->pluck('email_address')->values();

                return [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'status' => $this->enumValue($campaign->getAttribute('status')),
                    'people_count' => (int) $campaign->getAttribute('people_count'),
                    'steps_count' => $version?->activeSteps->count() ?? 0,
                    'mailboxes_count' => $campaign->mailboxes->count(),
                    'sender_emails' => $emails->take(3)->all(),
                    'senders_more' => max(0, $emails->count() - 3),
                    'sent_count' => (int) $campaign->getAttribute('sent_count'),
                    'replies_count' => (int) $campaign->getAttribute('replies_count'),
                    'interested_count' => (int) $campaign->getAttribute('interested_count'),
                    'queued_count' => (int) $campaign->getAttribute('queued_count'),
                    'bounced_count' => (int) $campaign->getAttribute('bounced_count'),
                    'unsubscribed_count' => (int) $campaign->getAttribute('unsubscribed_count'),
                    'updated_at' => $this->timestamp($campaign->getAttribute('updated_at')),
                ];
            });

        return Inertia::render('campaigns/Index', [
            'campaigns' => $campaigns,
        ]);
    }

    public function store(Request $request, CreateCampaignService $create): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $campaign = $create->handle($request->user(), $data['name']);

        return redirect()->route('campaigns.show', $campaign);
    }

    public function show(
        Request $request,
        Campaign $campaign,
        MailboxSenderIdentityService $senderIdentities,
        CampaignLaunchReadiness $readiness,
        CampaignOutcomeCounts $outcomes,
        ValidateSequenceStructure $sequences,
        PublishSequenceVersion $versions,
    ): Response {
        $this->authorize('view', $campaign);
        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        $campaign->load([
            'recipients' => fn ($query) => $query->where('workspace_id', $workspaceId),
            'recipients.mailbox' => fn ($query) => $query->where('workspace_id', $workspaceId),
            'recipients.threads' => fn ($query) => $query->where('workspace_id', $workspaceId),
            'recipients.sequenceVersion',
            'draftVersion.activeSteps',
            'publishedVersion.activeSteps',
            'mailboxes' => fn ($query) => $query
                ->where('mailboxes.workspace_id', $workspaceId)
                ->where('mailboxes.status', '!=', ConnectionStatus::Disconnected),
        ]);
        $availableMailboxes = [];

        foreach (
            Mailbox::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', '!=', ConnectionStatus::Disconnected)
                ->whereHas('connection', fn ($query) => $query->where('workspace_id', $workspaceId))
                ->get() as $mailbox
        ) {
            $effective = null;

            try {
                $effective = $senderIdentities->effective($mailbox);
            } catch (SenderIdentityUnavailable) {
                // Keep campaign setup visible while identity repair is pending.
            }

            $status = $mailbox->getAttribute('status');
            $availableMailboxes[] = [
                'id' => $mailbox->id,
                'email_address' => $mailbox->email_address,
                'status' => $status instanceof ConnectionStatus ? $status->value : (string) $status,
                'effective_reply_identity' => $effective,
                'sending_paused' => $mailbox->getAttribute('sending_paused_at') !== null,
                'sending_paused_reason' => $mailbox->getAttribute('sending_paused_reason'),
                'daily_send_cap' => app(MailboxSendBudget::class)->dailyCap($mailbox),
                'remaining_today' => app(MailboxSendBudget::class)->remainingDailyBudget($mailbox, includeReserved: true),
            ];
        }

        $people = $campaign->recipients
            ->filter(function (CampaignRecipient $person) use ($campaign, $workspaceId): bool {
                $mailbox = $person->mailbox;

                return (int) $person->getAttribute('campaign_id') === (int) $campaign->getKey()
                    && (int) $person->getAttribute('workspace_id') === $workspaceId
                    && ($mailbox === null || (int) $mailbox->getAttribute('workspace_id') === $workspaceId);
            })
            ->map(fn (CampaignRecipient $person): array => [
                'id' => $person->getKey(),
                'person_id' => $person->getAttribute('person_id') !== null
                    ? (int) $person->getAttribute('person_id')
                    : null,
                'name' => $person->getAttribute('full_name'),
                'email' => $person->getAttribute('email_address'),
                'company' => $person->getAttribute('company'),
                'status' => $person->displayStatus(),
                'lifecycle' => $this->enumValue($person->getAttribute('status')),
                'mailbox' => $person->mailbox?->email_address,
                'mailbox_id' => $person->getAttribute('mailbox_id'),
                'first_sent_at' => $this->timestamp($person->getAttribute('first_sent_at')),
                'thread_id' => $person->threads
                    ->first(fn (Thread $thread): bool => (int) $thread->getAttribute('workspace_id') === $workspaceId
                        && (int) $thread->getAttribute('mailbox_id') === (int) $person->getAttribute('mailbox_id')
                        && (int) $thread->getAttribute('campaign_recipient_id') === (int) $person->getKey())?->getKey(),
                'sequence_version' => $person->relationLoaded('sequenceVersion')
                    && $person->getRelation('sequenceVersion') instanceof SequenceVersion
                    ? $person->getRelation('sequenceVersion')->getAttribute('version')
                    : null,
            ]);

        $blockers = $readiness->blockers($campaign);
        $displayVersion = $campaign->draftVersion ?? $campaign->publishedVersion;
        $draftId = $campaign->getAttribute('draft_sequence_version_id');
        $publishedId = $campaign->getAttribute('published_sequence_version_id');
        $draft = is_numeric($draftId) ? SequenceVersion::query()->whereKey($draftId)->first() : null;
        $published = is_numeric($publishedId) ? SequenceVersion::query()->whereKey($publishedId)->first() : null;
        $hasDraft = (bool) ($draftId && $draftId !== $publishedId);
        $sequenceIssues = collect($sequences->handle($draft ?? $published))
            ->map(fn ($issue) => [
                'code' => $issue->code,
                'message' => $issue->message,
            ])
            ->all();

        return Inertia::render('campaigns/Show', [
            'composition' => app(CampaignCompositionView::class)->handle($campaign, $request->user()),
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $this->enumValue($campaign->getAttribute('status')),
                'send_mode' => $this->enumValue($campaign->getAttribute('send_mode')),
                'people' => $people,
                'steps' => $displayVersion?->activeSteps->map(fn (SequenceStep $step): array => [
                    'id' => $step->getKey(),
                    'title' => $step->getAttribute('title'),
                    'delay_minutes' => $step->getAttribute('delay_minutes'),
                    'send_as' => $this->enumValue($step->getAttribute('send_as')),
                    'subject' => $step->getAttribute('subject_template'),
                    'body' => $step->getAttribute('body_template'),
                    'subject_spintax_enabled' => (bool) $step->subject_spintax_enabled,
                    'body_spintax_enabled' => (bool) $step->body_spintax_enabled,
                ]),
                'mailboxes' => $campaign->mailboxes->pluck('id'),
                'daily_send_limit' => $campaign->getAttribute('daily_send_limit'),
                'mailbox_emails' => $campaign->mailboxes->pluck('email_address'),
                'timezone' => $campaign->timezone,
                'send_window_start' => $campaign->send_window_start,
                'send_window_end' => $campaign->send_window_end,
                'business_days_only' => $campaign->business_days_only,
                'open_tracking_enabled' => (bool) $campaign->open_tracking_enabled,
                'updated_at' => $this->timestamp($campaign->getAttribute('updated_at')),
                'launched_at' => $this->timestamp($campaign->getAttribute('launched_at')),
                'editable' => $this->campaignStatus($campaign)->allowsEditing(),
                'has_draft' => $hasDraft,
                'sequence_issues' => $sequenceIssues,
                'supported_variables' => RenderSequenceMessage::SUPPORTED_VARIABLES,
                'sequence' => [
                    'draft_version' => $draft?->getAttribute('version'),
                    'published_version' => $published?->getAttribute('version'),
                    'versions_count' => $campaign->versions()->count(),
                    'unstarted_count' => $hasDraft && $campaign->getAttribute('published_sequence_version_id')
                        ? $versions->unstartedRecipients($campaign)->count()
                        : 0,
                ],
            ],
            'readiness' => [
                'ready' => $blockers === [],
                'blockers' => $blockers,
            ],
            'outcomes' => $outcomes->counts($campaign),
            'availableMailboxes' => $availableMailboxes,
        ]);
    }

    public function update(
        UpdateCampaignRequest $request,
        Campaign $campaign,
        FrozenMailExperimentInputGuard $frozenInputs,
        GovernedCampaignInputGuard $governedInputs,
    ): RedirectResponse {
        $data = $request->validated();
        $workspace = $request->user()->currentWorkspace();
        $renamedOnly = count($data) === 1 && array_key_exists('name', $data);

        try {
            DB::transaction(function () use ($campaign, $workspace, $request, $data, $governedInputs, $frozenInputs): void {
                $campaign = Campaign::query()->where('workspace_id', $workspace->id)->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
                $governedInputs->assertCampaignMutable($campaign);
                $frozenInputs->assertCampaignMutable($campaign);

                if (isset($data['name'])) {
                    $campaign->forceFill(['name' => $data['name']])->save();
                }

                if (isset($data['send_mode'])) {
                    $campaign->forceFill(['send_mode' => SendMode::from($data['send_mode'])])->save();
                }

                if (array_key_exists('open_tracking_enabled', $data)) {
                    $campaign->forceFill(['open_tracking_enabled' => (bool) $data['open_tracking_enabled']])->save();
                }

                if (array_key_exists('daily_send_limit', $data)) {
                    $campaign->forceFill(['daily_send_limit' => $data['daily_send_limit']])->save();
                }

                $schedule = collect([
                    'send_window_start',
                    'send_window_end',
                    'business_days_only',
                ])->filter(fn (string $field): bool => array_key_exists($field, $data))
                    ->mapWithKeys(fn (string $field): array => [
                        $field => $field === 'business_days_only' ? (bool) $data[$field] : $data[$field],
                    ])->all();

                if ($schedule !== []) {
                    $campaign->forceFill($schedule)->save();
                }

                if (isset($data['mailbox_ids'])) {
                    $owned = $workspace->mailboxes()
                        ->where('status', '!=', ConnectionStatus::Disconnected)
                        ->whereIn('id', $data['mailbox_ids'])
                        ->pluck('id');
                    /** @var array<int, int|string> $mailboxIds */
                    $mailboxIds = $data['mailbox_ids'];
                    $ordered = collect($mailboxIds)
                        ->map(fn ($id) => (int) $id)
                        ->filter(fn (int $id) => $owned->contains($id))
                        ->unique()
                        ->values();
                    $campaign->mailboxes()->sync(
                        $ordered->mapWithKeys(fn ($id, $index) => [$id => ['position' => $index + 1]])->all()
                    );
                    app(AssignUnassignedDraftRecipients::class)->handle($campaign);
                }
                if (($data['automatic_allowance'] ?? false) && $campaign->getAttribute('status') === CampaignStatus::Draft && $campaign->launched_at === null) {
                    if ($campaign->getAttribute('send_mode') === SendMode::Rotate && $campaign->mailboxes()->exists()) {
                        app(AssignUnassignedDraftRecipients::class)->handle($campaign, redistribute: true);
                    }
                    app(PrepareCampaignSendingAllowance::class)->handle($campaign->fresh(), $request->user());
                }
            }, 3);
        } catch (GovernedCampaignInputMutation $exception) {
            return $this->governedConflict($exception, 'campaign');
        } catch (FrozenMailExperimentInputMutation $exception) {
            return back()->withErrors(['campaign' => $exception->getMessage()]);
        }

        if ($renamedOnly) {
            return back()->with('success', 'Campaign renamed.');
        }

        return back();
    }

    public function redistributeInboxes(UpdateCampaignRequest $request, Campaign $campaign): RedirectResponse
    {
        abort_unless((int) $campaign->getAttribute('workspace_id') === (int) $request->user()->currentWorkspace()->id, 404);
        abort_unless($request->user()->currentWorkspace()->isOwner($request->user()), 403);
        try {
            app(AssignUnassignedDraftRecipients::class)->handle($campaign, redistribute: true);
        } catch (GovernedCampaignInputMutation $exception) {
            return $this->governedConflict($exception, 'campaign');
        } catch (FrozenMailExperimentInputMutation $exception) {
            return back()->withErrors(['campaign' => $exception->getMessage()]);
        }

        return back()->with('success', 'Unsent draft prospects redistributed. Prepare a fresh exact review before sending.');
    }

    public function launch(LaunchCampaignRequest $request, Campaign $campaign, LaunchCampaignService $launch): RedirectResponse
    {
        try {
            $launch->handle($campaign, $request->user());
        } catch (GovernedCampaignInputMutation|GovernedCampaignLaunchRequired $exception) {
            return $this->governedConflict($exception, 'campaign');
        }

        return back()->with('success', 'Campaign launched.');
    }

    public function pause(LaunchCampaignRequest $request, Campaign $campaign, LaunchCampaignService $launch): RedirectResponse
    {
        $launch->pause($campaign);

        return back()->with('success', 'Campaign paused.');
    }

    public function resume(LaunchCampaignRequest $request, Campaign $campaign, LaunchCampaignService $launch): RedirectResponse
    {
        try {
            $launch->resume($campaign, $request->user());
        } catch (GovernedCampaignInputMutation|GovernedCampaignLaunchRequired $exception) {
            return $this->governedConflict($exception, 'campaign');
        }

        return back()->with('success', 'Campaign resumed.');
    }

    public function complete(LaunchCampaignRequest $request, Campaign $campaign, LaunchCampaignService $launch): RedirectResponse
    {
        $launch->complete($campaign);

        return back()->with('success', 'Campaign completed.');
    }

    public function previewImport(PreviewRecipientImportRequest $request, Campaign $campaign, PreviewRecipientImport $preview): RedirectResponse
    {
        $data = $request->validated();

        try {
            $rows = $data['source'] === 'sample' ? $preview->sample() : $data['rows'];
            $import = $preview->handle(
                $request->user(),
                $campaign,
                $data['source'] === 'csv' ? ImportSource::Csv : ImportSource::Paste,
                $rows,
                $data['mapping'] ?? ['email' => 'email', 'name' => 'name', 'company' => 'company'],
                $data['filename'] ?? null,
            );
        } catch (GovernedCampaignInputMutation $exception) {
            return $this->governedConflict($exception, 'import');
        } catch (FrozenMailExperimentInputMutation $exception) {
            return back()->withErrors(['import' => $exception->getMessage()]);
        }

        return back()->with('import', [
            'id' => $import->id,
            'mapping_version' => $import->mapping_version,
            'ready' => $import->ready,
            'invalid' => $import->invalid,
            'duplicate' => $import->duplicate,
            'suppressed' => $import->suppressed,
        ]);
    }

    public function importRows(Request $request, Campaign $campaign, RecipientImport $import): JsonResponse
    {
        $this->authorize('update', $campaign);
        $this->authorize('update', $import);
        abort_unless((int) $import->campaign_id === (int) $campaign->id
            && (int) $import->getAttribute('workspace_id') === (int) $campaign->getAttribute('workspace_id'), 404);
        $data = $request->validate(['issue' => ['nullable', 'in:ready,invalid,duplicate,suppressed'], 'page' => ['nullable', 'integer', 'min:1']]);
        $rows = $import->rows()->when($data['issue'] ?? null, fn ($query, $issue) => $query->where('issue', $issue))
            ->orderBy('row_number')->paginate(25);

        return response()->json([
            'data' => $rows->getCollection()->map(fn ($row) => [
                'row_number' => $row->getAttribute('row_number'), 'email' => $row->getAttribute('email_address'),
                'first_name' => $row->getAttribute('first_name'), 'company' => $row->getAttribute('company'), 'issue' => $row->getAttribute('issue')->value,
            ]),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    public function commitImport(CommitRecipientImportRequest $request, Campaign $campaign, RecipientImport $import, CommitRecipientImport $commit): RedirectResponse
    {
        $data = $request->validated();
        try {
            $commit->handle($import, $data['expected_mapping_version']);
        } catch (GovernedCampaignInputMutation $exception) {
            return $this->governedConflict($exception, 'import');
        } catch (FrozenMailExperimentInputMutation $exception) {
            return back()->withErrors(['import' => $exception->getMessage()]);
        } catch (ValidationException $exception) {
            return back()->withErrors([
                'import' => collect($exception->errors())->flatten()->first()
                    ?? 'Could not add the reviewed people.',
            ]);
        }

        $campaign->refresh();
        $message = 'People added.';
        $status = $campaign->getAttribute('status');

        if ($status === CampaignStatus::Completed || $status === CampaignStatus::Completed->value) {
            $message = 'People added. Campaign is completed — resume it to start sending to newly added people.';
        }

        return back()->with('success', $message);
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }

    private function campaignStatus(Campaign $campaign): CampaignStatus
    {
        $status = $campaign->getAttribute('status');

        return $status instanceof CampaignStatus ? $status : CampaignStatus::from((string) $status);
    }

    private function governedConflict(
        GovernedCampaignInputMutation|GovernedCampaignLaunchRequired $exception,
        string $field,
    ): RedirectResponse {
        $code = $exception instanceof GovernedCampaignInputMutation
            ? GovernedCampaignInputMutation::CODE
            : GovernedCampaignLaunchRequired::CODE;

        return back()
            ->withErrors([$field => "{$code}: {$exception->getMessage()}"]);
    }
}
