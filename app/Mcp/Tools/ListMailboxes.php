<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Http\Resources\Api\V1\MailboxResource;
use App\Models\Mailbox;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists connected mailboxes in the authenticated workspace, including connection status, sync health, daily send cap, and whether sending is paused. Defaults to 20 results (maximum 100). Does not mutate data.')]
#[IsReadOnly]
class ListMailboxes extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return $this->paginationSchema($schema);
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::MailboxesRead->value)) {
            return $denied;
        }

        $workspace = $this->actor($request)->currentWorkspace();
        $mailboxes = Mailbox::query()
            ->where('workspace_id', $workspace->id)
            ->whereHas('connection', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->with(['connection', 'syncStates'])
            ->where('status', '!=', ConnectionStatus::Disconnected)
            ->orderBy('email_address')
            ->paginate($this->perPage($request), ['*'], 'page', $this->page($request));

        return $this->json($this->pagePayload(
            MailboxResource::collection($mailboxes->getCollection())->resolve(),
            $mailboxes->currentPage(),
            $mailboxes->perPage(),
            $mailboxes->total(),
        ));
    }
}
