<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Campaign;
use App\Models\SequenceVersion;
use Illuminate\Http\Request;

class CampaignSummaryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $campaign = $this->model(Campaign::class);
        $draft = $campaign->draftVersion;
        $published = $campaign->publishedVersion;
        $version = $draft instanceof SequenceVersion
            ? $draft
            : ($published instanceof SequenceVersion ? $published : null);
        $emails = $campaign->relationLoaded('mailboxes')
            ? $campaign->mailboxes->pluck('email_address')->values()
            : collect();

        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $this->enumValue($campaign->getAttribute('status')),
            'people_count' => (int) $campaign->getAttribute('people_count'),
            'steps_count' => $version !== null ? $version->activeSteps->count() : 0,
            'mailboxes_count' => $campaign->relationLoaded('mailboxes') ? $campaign->mailboxes->count() : 0,
            'sender_emails' => $emails->take(3)->all(),
            'sent_count' => (int) $campaign->getAttribute('sent_count'),
            'replies_count' => (int) $campaign->getAttribute('replies_count'),
            'interested_count' => (int) $campaign->getAttribute('interested_count'),
            'queued_count' => (int) $campaign->getAttribute('queued_count'),
            'bounced_count' => (int) $campaign->getAttribute('bounced_count'),
            'unsubscribed_count' => (int) $campaign->getAttribute('unsubscribed_count'),
            'updated_at' => $this->iso8601($campaign->getAttribute('updated_at')),
        ];
    }
}
