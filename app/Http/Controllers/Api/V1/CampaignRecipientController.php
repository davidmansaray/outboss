<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Services\PauseResumeRecipient;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCampaignRecipientRequest;
use App\Http\Resources\Api\V1\CampaignRecipientResource;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CampaignRecipientController extends Controller
{
    public function index(Request $request, Campaign $campaign): AnonymousResourceCollection
    {
        $this->authorize('view', $campaign);
        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        $status = $request->query('status');

        $recipients = $campaign->recipients()
            ->where('workspace_id', $workspaceId)
            ->with('mailbox')
            ->when(
                is_string($status) && $status !== '',
                function ($query) use ($status): void {
                    $enum = RecipientStatus::tryFrom($status);

                    if ($enum !== null) {
                        $query->where('status', $enum);
                    }
                },
            )
            ->orderBy('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 15))))
            ->withQueryString();

        return CampaignRecipientResource::collection($recipients);
    }

    public function update(UpdateCampaignRecipientRequest $request, CampaignRecipient $recipient, PauseResumeRecipient $actions): CampaignRecipientResource
    {
        $this->authorize('update', $recipient);

        try {
            if ($request->input('action') === 'pause') {
                $actions->pause($recipient);
            } elseif ($request->input('action') === 'resume') {
                $actions->resume($recipient);
            } else {
                $workspace = $request->user()->currentWorkspace();
                $mailbox = Mailbox::query()
                    ->where('workspace_id', $workspace->id)
                    ->findOrFail($request->integer('mailbox_id'));
                $actions->pinMailbox($recipient, $mailbox);
            }
        } catch (GovernedCampaignInputMutation $exception) {
            throw $exception;
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'mailbox_id' => 'Mailbox cannot be changed after the first send.',
            ]);
        }

        return new CampaignRecipientResource($recipient->fresh('mailbox'));
    }

    public function destroy(CampaignRecipient $recipient, PauseResumeRecipient $actions): Response
    {
        $this->authorize('delete', $recipient);
        $actions->remove($recipient);

        return response()->noContent();
    }
}
