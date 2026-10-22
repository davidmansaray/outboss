<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\MailboxAssignment;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use App\Models\Campaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Assign draft senders; redistribution requires an explicit owner action and fresh review. */
final class AssignUnassignedDraftRecipients
{
    public function handle(Campaign $campaign, bool $redistribute = false): void
    {
        DB::transaction(function () use ($campaign, $redistribute): void {
            $campaign = Campaign::query()->where('workspace_id', $campaign->getAttribute('workspace_id'))
                ->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($campaign->getAttribute('status') !== CampaignStatus::Draft || $campaign->launched_at !== null) {
                if ($redistribute) {
                    throw ValidationException::withMessages(['sending' => 'Only an unlaunched draft can redistribute prospects.']);
                }

                return;
            }
            app(GovernedCampaignInputGuard::class)->assertCampaignMutable($campaign);
            app(FrozenMailExperimentInputGuard::class)->assertCampaignAudienceMutable($campaign);
            $mailboxes = $campaign->mailboxes()->where('mailboxes.workspace_id', $campaign->getAttribute('workspace_id'))
                ->orderByPivot('position')
                ->when($redistribute, fn ($query) => $query->where('mailboxes.status', 'connected')->whereNull('mailboxes.sending_paused_at'))
                ->get();
            if ($mailboxes->isEmpty()) {
                if ($redistribute) {
                    throw ValidationException::withMessages(['sending' => 'Save at least one connected sending inbox first.']);
                }

                return;
            }
            $pin = $campaign->getAttribute('send_mode') === SendMode::Pin;
            if ($redistribute && $pin) {
                throw ValidationException::withMessages(['sending' => 'Save Rotate across inboxes before redistributing prospects.']);
            }
            $recipients = $campaign->recipients()->where('workspace_id', $campaign->getAttribute('workspace_id'))
                ->when(! $redistribute, fn ($query) => $query->whereNull('mailbox_id'))
                ->whereNull('first_sent_at')->whereNull('send_lease_token')->whereDoesntHave('sendAttempts')
                ->where('status', RecipientStatus::Queued)->orderBy('id')->lockForUpdate()->get();
            $assigned = array_fill_keys($mailboxes->modelKeys(), 0);
            $caps = $mailboxes->mapWithKeys(fn ($mailbox) => [$mailbox->id => app(MailboxSendBudget::class)->dailyCap($mailbox)]);
            foreach ($recipients as $index => $recipient) {
                $mailbox = $mailboxes[$pin ? 0 : $index % $mailboxes->count()];
                if ($redistribute) {
                    // Fill inboxes proportionally to capacity, rather than
                    // stranding half the audience on a much smaller inbox.
                    $mailbox = $mailboxes->sortBy(fn ($box) => ($assigned[$box->id] + 1) / $caps[$box->id])->first();
                    $assigned[$mailbox->id]++;
                }
                $recipient->forceFill([
                    'mailbox_id' => $mailbox->id,
                    'mailbox_assignment' => $pin ? MailboxAssignment::CampaignPin : MailboxAssignment::Rotation,
                ])->save();
            }
        }, 3);
    }
}
