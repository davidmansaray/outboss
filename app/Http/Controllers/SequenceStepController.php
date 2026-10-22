<?php

namespace App\Http\Controllers;

use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Exceptions\InvalidSequenceTemplate;
use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Campaigns\Services\ResolveStepSubject;
use App\Domain\Campaigns\Services\SequenceStepEditor;
use App\Domain\Campaigns\Services\TestSequenceStep;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Http\Requests\ReorderSequenceStepsRequest;
use App\Http\Requests\StoreSequenceStepRequest;
use App\Http\Requests\TestSequenceStepRequest;
use App\Http\Requests\UpdateSequenceStepRequest;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class SequenceStepController extends Controller
{
    public function store(StoreSequenceStepRequest $request, Campaign $campaign, SequenceStepEditor $editor): RedirectResponse
    {
        $this->authorize('update', $campaign);

        try {
            $editor->add($campaign, $request->string('title', 'Follow-up')->toString());
        } catch (GovernedCampaignInputMutation $exception) {
            return $this->governedConflict($exception);
        } catch (RuntimeException) {
            return back()->withErrors(['sequence' => 'Pause the campaign before editing the sequence.']);
        }

        return back()->with('success', 'Follow-up added.');
    }

    public function reorder(ReorderSequenceStepsRequest $request, Campaign $campaign, SequenceStepEditor $editor): RedirectResponse
    {
        $this->authorize('update', $campaign);

        try {
            /** @var list<int> $stepIds */
            $stepIds = array_values(array_map(intval(...), $request->validated('step_ids')));
            $editor->reorder($campaign, $stepIds);
        } catch (GovernedCampaignInputMutation $exception) {
            return $this->governedConflict($exception);
        } catch (RuntimeException $exception) {
            $message = match ($exception->getMessage()) {
                'campaign_not_editable' => 'Pause the campaign before editing the sequence.',
                'sequence_stale' => 'The sequence changed. Reload and try again.',
                default => 'The sequence could not be reordered.',
            };

            return back()->withErrors(['sequence' => $message]);
        }

        return back()->with('success', 'Sequence order saved.');
    }

    public function update(UpdateSequenceStepRequest $request, SequenceStep $step, SequenceStepEditor $editor): RedirectResponse
    {
        $this->authorize('update', $step);

        $data = $request->validated();
        if (isset($data['send_as'])) {
            $data['send_as'] = SequenceSendAs::from($data['send_as']);
        }

        try {
            $editor->update($step, $data);
        } catch (GovernedCampaignInputMutation $exception) {
            return $this->governedConflict($exception);
        } catch (RuntimeException) {
            return back()->withErrors(['sequence' => 'Published steps cannot be edited.']);
        }

        return back()->with('success', 'Step saved.');
    }

    public function destroy(SequenceStep $step, SequenceStepEditor $editor): RedirectResponse
    {
        $this->authorize('delete', $step);

        try {
            $editor->delete($step);
        } catch (GovernedCampaignInputMutation $exception) {
            return $this->governedConflict($exception);
        } catch (RuntimeException) {
            return back()->withErrors(['sequence' => 'Published steps cannot be deleted.']);
        }

        return back()->with('success', 'Step removed.');
    }

    public function preview(
        Request $request,
        SequenceStep $step,
        RenderSequenceMessage $renderer,
        ResolveStepSubject $subjects,
    ): JsonResponse {
        $this->authorize('view', $step);
        $versionId = $step->getAttribute('sequence_version_id');
        $version = is_numeric($versionId)
            ? SequenceVersion::query()->whereKey($versionId)->first()
            : null;
        $campaignId = $version?->getAttribute('campaign_id');
        $campaign = is_numeric($campaignId)
            ? Campaign::query()->whereKey($campaignId)->first()
            : null;

        if (! $campaign instanceof Campaign) {
            abort(404);
        }

        $workspaceId = (int) $campaign->getAttribute('workspace_id');

        if ($request->filled('recipient_id')) {
            $recipient = CampaignRecipient::query()
                ->whereKey($request->integer('recipient_id'))
                ->where('campaign_id', $campaign->id)
                ->where('workspace_id', $workspaceId)
                ->firstOrFail();
            $recipientSeed = null;
        } else {
            $recipient = new CampaignRecipient([
                'workspace_id' => $workspaceId,
                'first_name' => 'Jane',
                'company' => 'Acme',
                'email_address' => 'jane@example.com',
            ]);
            $recipientSeed = 'sample';
        }

        $subjectSource = $subjects->source($step);
        $clone = tap(clone $step, fn (SequenceStep $copy) => $copy->subject_template = $subjects->handle($step));

        try {
            $rendered = $renderer->handle(
                $clone,
                $recipient,
                test: false,
                subjectSeedStep: $subjectSource,
                recipientSeed: $recipientSeed,
            );
        } catch (InvalidSequenceTemplate $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [$exception->field => [$exception->getMessage()]],
            ], 422);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'subject' => $rendered['subject'],
            'body' => $rendered['body'],
            'to' => $recipient->email_address,
            'recipient_id' => $recipient->id,
        ]);
    }

    public function test(TestSequenceStepRequest $request, SequenceStep $step, TestSequenceStep $tester): RedirectResponse
    {
        $this->authorize('update', $step);
        $workspace = $request->user()->currentWorkspace();
        $mailbox = Mailbox::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($request->integer('mailbox_id'));
        $this->authorize('update', $mailbox);

        try {
            $tester->handle(
                $request->user(),
                $step,
                $mailbox,
                $request->string('to')->toString(),
                $request->string('client_operation_id')->toString(),
            );
        } catch (SenderIdentityUnavailable) {
            return back()->withErrors(['mailbox_id' => SenderIdentityUnavailable::USER_MESSAGE]);
        } catch (GovernedCampaignInputMutation $exception) {
            return $this->governedConflict($exception);
        } catch (InvalidSequenceTemplate $exception) {
            return back()->withErrors([$exception->field => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['template' => $exception->getMessage()]);
        }

        return back()->with('success', 'Test email sent.');
    }

    private function governedConflict(GovernedCampaignInputMutation $exception): RedirectResponse
    {
        return back()
            ->withErrors(['sequence' => GovernedCampaignInputMutation::CODE.': '.$exception->getMessage()]);
    }
}
