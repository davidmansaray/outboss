<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\SequenceVersion;
use Illuminate\Http\Request;

class CampaignResource extends ApiResource
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
        $status = $campaign->getAttribute('status');

        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $this->enumValue($status),
            'send_mode' => $this->enumValue($campaign->getAttribute('send_mode')),
            'timezone' => $campaign->timezone,
            'send_window_start' => $campaign->send_window_start,
            'send_window_end' => $campaign->send_window_end,
            'business_days_only' => (bool) $campaign->business_days_only,
            'open_tracking_enabled' => (bool) $campaign->open_tracking_enabled,
            'mailbox_ids' => $campaign->relationLoaded('mailboxes')
                ? $campaign->mailboxes->pluck('id')->values()->all()
                : [],
            'steps' => SequenceStepResource::collection($version !== null ? $version->activeSteps : collect()),
            'editable' => $status instanceof CampaignStatus && $status->allowsEditing(),
            'launched_at' => $this->iso8601($campaign->getAttribute('launched_at')),
            'paused_at' => $this->iso8601($campaign->getAttribute('paused_at')),
            'completed_at' => $this->iso8601($campaign->getAttribute('completed_at')),
            'created_at' => $this->iso8601($campaign->getAttribute('created_at')),
            'updated_at' => $this->iso8601($campaign->getAttribute('updated_at')),
        ];
    }
}
