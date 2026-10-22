<?php

namespace App\Http\Controllers;

use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Services\PauseResumeRecipient;
use App\Http\Requests\UpdateCampaignRecipientRequest;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class CampaignRecipientController extends Controller
{
    public function update(UpdateCampaignRecipientRequest $request, CampaignRecipient $recipient, PauseResumeRecipient $actions): RedirectResponse
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
            return $this->governedConflict($exception);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['mailbox_id' => 'Mailbox cannot be changed after the first send.']);
        }

        return back()->with('success', 'Recipient updated.');
    }

    public function destroy(CampaignRecipient $recipient, PauseResumeRecipient $actions): RedirectResponse
    {
        $this->authorize('delete', $recipient);
        try {
            $actions->remove($recipient);
        } catch (GovernedCampaignInputMutation $exception) {
            return $this->governedConflict($exception);
        }

        return back()->with('success', 'Recipient removed.');
    }

    private function governedConflict(GovernedCampaignInputMutation $exception): RedirectResponse
    {
        return back()
            ->withErrors(['recipient' => GovernedCampaignInputMutation::CODE.': '.$exception->getMessage()]);
    }
}
