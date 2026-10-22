<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Services\PauseResumeRecipient;
use App\Http\Resources\Api\V1\CampaignRecipientResource;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use RuntimeException;

#[Description('Pauses or resumes one campaign recipient, or pins them to a mailbox. Provide exactly one of action (pause|resume) or mailbox_id. Mailbox cannot change after the first send. Does not send email.')]
class UpdateRecipient extends OutbossTool
{
    public function __construct(private PauseResumeRecipient $actions) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'recipient_id' => $schema->integer()
                ->required()
                ->description('The campaign recipient to update.'),
            'action' => $schema->string()
                ->enum(['pause', 'resume'])
                ->description('pause holds the recipient; resume returns them to the send queue. Do not combine with mailbox_id.'),
            'mailbox_id' => $schema->integer()
                ->description('Pin this recipient to a workspace mailbox. Do not combine with action. Fails after the first send.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsWrite->value)) {
            return $denied;
        }

        $data = $request->validate([
            'recipient_id' => ['required', 'integer'],
            'action' => ['nullable', 'in:pause,resume'],
            'mailbox_id' => ['nullable', 'integer'],
        ]);
        $hasAction = filled($data['action'] ?? null);
        $hasMailbox = filled($data['mailbox_id'] ?? null);

        if ($hasAction === $hasMailbox) {
            throw ValidationException::withMessages([
                'action' => 'Provide exactly one of action or mailbox_id.',
            ]);
        }

        $recipient = $this->authorised($request, CampaignRecipient::class, (int) $data['recipient_id'], 'update');

        try {
            if (($data['action'] ?? null) === 'pause') {
                $this->actions->pause($recipient);
            } elseif (($data['action'] ?? null) === 'resume') {
                $this->actions->resume($recipient);
            } else {
                $workspace = $this->actor($request)->currentWorkspace();
                $mailbox = Mailbox::query()
                    ->where('workspace_id', $workspace->id)
                    ->findOrFail((int) $data['mailbox_id']);
                $this->authorised($request, Mailbox::class, $mailbox->id, 'view');
                $this->actions->pinMailbox($recipient, $mailbox);
            }
        } catch (GovernedCampaignInputMutation $exception) {
            return $this->governedCampaignError($exception);
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'mailbox_id' => 'Mailbox cannot be changed after the first send.',
            ]);
        }

        return $this->json((new CampaignRecipientResource($recipient->fresh('mailbox')))->resolve());
    }
}
