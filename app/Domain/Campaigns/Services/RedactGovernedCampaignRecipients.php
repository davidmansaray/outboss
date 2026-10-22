<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Models\CampaignRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Stop and redact compiled recipient copies during an authorised canonical deletion. */
final class RedactGovernedCampaignRecipients
{
    public function __construct(private readonly GovernedCampaignInputGuard $frozenInputs) {}

    public function forPerson(int $workspaceId, int $personId): void
    {
        $this->redact($workspaceId, 'person_id', $personId, 'person_deleted');
    }

    public function forAccount(int $workspaceId, int $accountId): void
    {
        $this->redact($workspaceId, 'account_id', $accountId, 'account_deleted');
    }

    private function redact(int $workspaceId, string $column, int $value, string $reason): void
    {
        if (! Schema::hasTable('execution_plan_action_compilations')) {
            return;
        }

        $recipientIds = DB::table('campaign_recipients as recipients')
            ->leftJoin('execution_plan_campaigns as campaigns', 'campaigns.campaign_id', '=', 'recipients.campaign_id')
            ->leftJoin('execution_plan_action_compilations as compilations', 'compilations.campaign_recipient_id', '=', 'recipients.id')
            ->where('recipients.workspace_id', $workspaceId)
            ->where("recipients.{$column}", $value)
            ->where(function ($query): void {
                $query->whereNotNull('campaigns.id')->orWhereNotNull('compilations.id');
            })
            ->distinct()
            ->orderBy('recipients.id')
            ->pluck('recipients.id');

        foreach ($recipientIds as $recipientId) {
            $recipient = CampaignRecipient::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey((int) $recipientId)
                ->lockForUpdate()
                ->first();
            if (! $recipient instanceof CampaignRecipient) {
                continue;
            }

            $this->frozenInputs->runRecipientCanonicalDeletion(
                $recipient,
                function () use ($recipient, $workspaceId, $reason): void {
                    $status = $recipient->getAttribute('status');
                    $status = $status instanceof RecipientStatus
                        ? $status
                        : RecipientStatus::tryFrom((string) $status);
                    $terminal = $status?->isTerminal() === true || $status === RecipientStatus::Completed;
                    $redactedAddress = sprintf(
                        'redacted-%s@deleted.invalid',
                        substr(hash('sha256', $workspaceId.':'.$recipient->getKey()), 0, 24),
                    );

                    $recipient->forceFill([
                        'person_id' => null,
                        'account_id' => null,
                        'email_address' => $redactedAddress,
                        'email_normalized' => $redactedAddress,
                        'email_domain' => 'deleted.invalid',
                        'full_name' => null,
                        'first_name' => null,
                        'company' => null,
                        'status' => $terminal ? $status : RecipientStatus::Removed,
                        'next_send_at' => null,
                        'stopped_at' => $recipient->stopped_at ?? now(),
                        'stop_reason' => $reason,
                        'removed_at' => $terminal ? $recipient->removed_at : ($recipient->removed_at ?? now()),
                        'governed_subject_snapshot' => null,
                        'governed_body_text_snapshot' => null,
                    ])->save();
                },
            );
        }
    }
}
