<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Models\CampaignRecipient;
use App\Models\SuppressionEntry;
use Illuminate\Validation\ValidationException;

/** Legacy unsubscribe/bounce history remains binding even without a suppression row. */
final class AssertHumanRecipientContactable
{
    public function handle(CampaignRecipient $recipient): void
    {
        $workspaceId = (int) $recipient->getAttribute('workspace_id');
        $suppressed = SuppressionEntry::query()->where('workspace_id', $workspaceId)
            ->where('email_normalized', $recipient->email_normalized)->exists();
        $history = CampaignRecipient::query()->where('workspace_id', $workspaceId)
            ->whereIn('status', [RecipientStatus::Unsubscribed->value, RecipientStatus::Bounced->value])
            ->where(function ($query) use ($recipient): void {
                $query->where('email_normalized', $recipient->email_normalized);
                if ($recipient->person_id !== null) {
                    $query->orWhere('person_id', $recipient->person_id);
                }
            })->exists();
        if ($suppressed || $history) {
            throw ValidationException::withMessages([
                'audience' => 'Every selected prospect must be contactable. '.$recipient->email_address
                    .' is suppressed or has an unsubscribe/bounce history. Remove this prospect from the campaign; no recipients were removed automatically.',
            ]);
        }
    }
}
