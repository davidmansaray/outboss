<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Services\TestSequenceStep;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Models\Mailbox;
use App\Models\SequenceStep;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Sends real email: sends a rendered sequence step to a test address from the chosen mailbox. Confirm with the user before calling. client_operation_id must be a UUID used for idempotency.')]
#[IsDestructive]
#[IsOpenWorld]
class SendTestStep extends OutbossTool
{
    public function __construct(private TestSequenceStep $tester) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'step_id' => $schema->integer()
                ->required()
                ->description('The sequence step to send as a test.'),
            'mailbox_id' => $schema->integer()
                ->required()
                ->description('Workspace mailbox that will send the test.'),
            'to' => $schema->string()
                ->required()
                ->description('Test recipient email address.'),
            'client_operation_id' => $schema->string()
                ->required()
                ->description('UUID used to make the send idempotent. Reuse the same value to avoid a duplicate send.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::InboxSend->value)) {
            return $denied;
        }

        $data = $request->validate([
            'step_id' => ['required', 'integer'],
            'mailbox_id' => ['required', 'integer'],
            'to' => ['required', 'email'],
            'client_operation_id' => ['required', 'uuid'],
        ]);
        $step = $this->authorised($request, SequenceStep::class, (int) $data['step_id'], 'update');
        $mailbox = $this->authorised($request, Mailbox::class, (int) $data['mailbox_id'], 'update');

        try {
            $this->tester->handle(
                $this->actor($request),
                $step,
                $mailbox,
                $data['to'],
                $data['client_operation_id'],
            );
        } catch (GovernedCampaignInputMutation $exception) {
            return $this->governedCampaignError($exception);
        } catch (SenderIdentityUnavailable) {
            return $this->senderIdentityError();
        }

        return $this->json(['sent' => true]);
    }
}
