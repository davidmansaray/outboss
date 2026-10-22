<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Services\ApplyInboundCampaignEffects;
use App\Domain\Mail\Services\UniboxInbox;
use App\Models\Thread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Applies a triage classification to a thread and updates the linked campaign recipient when present. Actions: interested, replied, treat_as_reply, not_now, wrong_person, remove_bad_address, remove_from_campaign. Does not send email.')]
class ClassifyThread extends OutbossTool
{
    public const ACTIONS = [
        'interested',
        'replied',
        'treat_as_reply',
        'not_now',
        'wrong_person',
        'remove_bad_address',
        'remove_from_campaign',
    ];

    public function __construct(
        private ApplyInboundCampaignEffects $effects,
        private UniboxInbox $inbox,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread_id' => $schema->integer()
                ->required()
                ->description('The thread to classify.'),
            'action' => $schema->string()
                ->enum(self::ACTIONS)
                ->required()
                ->description('Triage action. interested, replied, and treat_as_reply keep the person reachable; remove_* take them off the campaign.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::InboxSend->value)) {
            return $denied;
        }

        $data = $request->validate([
            'thread_id' => ['required', 'integer'],
            'action' => ['required', 'string', 'in:'.implode(',', self::ACTIONS)],
        ]);
        $thread = $this->authorised($request, Thread::class, (int) $data['thread_id'], 'update');
        $action = $data['action'];
        $previous = $this->inbox->workspaceRecipient($thread)?->getAttribute('status');
        $previousStatus = $previous instanceof RecipientStatus
            ? $previous
            : RecipientStatus::tryFrom((string) $previous);

        match ($action) {
            'interested' => $this->effects->triage($thread, RecipientStatus::Interested),
            'replied', 'treat_as_reply' => $this->effects->triage($thread, RecipientStatus::Replied),
            'not_now' => $this->effects->triage($thread, RecipientStatus::NotNow),
            'wrong_person' => $this->effects->triage($thread, RecipientStatus::WrongPerson),
            'remove_bad_address' => $this->effects->removeBadAddress($thread),
            'remove_from_campaign' => $this->effects->removeFromCampaign($thread),
            default => throw ValidationException::withMessages([
                'action' => 'Unknown classification action.',
            ]),
        };

        $notice = null;

        if ($previousStatus === RecipientStatus::Unsubscribed
            && in_array($action, ['interested', 'replied', 'treat_as_reply', 'not_now', 'wrong_person'], true)) {
            $notice = 'This person stays suppressed from the earlier unsubscribe — manage suppressions in Settings.';
        }

        return $this->json([
            'id' => $thread->id,
            'action' => $action,
            'notice' => $notice,
        ]);
    }
}
