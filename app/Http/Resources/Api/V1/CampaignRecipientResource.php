<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use Illuminate\Http\Request;

class CampaignRecipientResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $recipient = $this->model(CampaignRecipient::class);
        $mailbox = $recipient->mailbox;

        return [
            'id' => $recipient->id,
            'person_id' => $recipient->getAttribute('person_id') !== null
                ? (int) $recipient->getAttribute('person_id')
                : null,
            'name' => $recipient->full_name,
            'email' => $recipient->email_address,
            'company' => $recipient->company,
            'status' => $recipient->displayStatus(),
            'lifecycle' => $this->enumValue($recipient->getAttribute('status')),
            'mailbox_id' => $recipient->mailbox_id,
            'mailbox' => $mailbox instanceof Mailbox ? $mailbox->email_address : null,
            'first_sent_at' => $this->iso8601($recipient->getAttribute('first_sent_at')),
            'paused_at' => $this->iso8601($recipient->getAttribute('paused_at')),
            'created_at' => $this->iso8601($recipient->getAttribute('created_at')),
            'updated_at' => $this->iso8601($recipient->getAttribute('updated_at')),
        ];
    }
}
