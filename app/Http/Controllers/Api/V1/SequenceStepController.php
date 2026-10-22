<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Exceptions\InvalidSequenceTemplate;
use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Campaigns\Services\ResolveStepSubject;
use App\Domain\Campaigns\Services\SequenceStepEditor;
use App\Domain\Campaigns\Services\TestSequenceStep;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Http\Controllers\Api\V1\Concerns\MapsDomainExceptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderSequenceStepsRequest;
use App\Http\Requests\StoreSequenceStepRequest;
use App\Http\Requests\TestSequenceStepRequest;
use App\Http\Requests\UpdateSequenceStepRequest;
use App\Http\Resources\Api\V1\SequenceStepResource;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class SequenceStepController extends Controller
{
    use MapsDomainExceptions;

    public function store(StoreSequenceStepRequest $request, Campaign $campaign, SequenceStepEditor $editor): JsonResponse
    {
        $this->authorize('update', $campaign);

        try {
            $step = $editor->add($campaign, $request->string('title', 'Follow-up')->toString());
        } catch (GovernedCampaignInputMutation $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $this->failSequence($exception);
        }

        return (new SequenceStepResource($step))
            ->response()
            ->setStatusCode(201);
    }

    public function reorder(ReorderSequenceStepsRequest $request, Campaign $campaign, SequenceStepEditor $editor): JsonResponse
    {
        $this->authorize('update', $campaign);

        try {
            /** @var list<int> $stepIds */
            $stepIds = array_values(array_map(intval(...), $request->validated('step_ids')));
            $editor->reorder($campaign, $stepIds);
        } catch (GovernedCampaignInputMutation $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $this->failSequence($exception);
        }

        $campaign->refresh()->load(['draftVersion.activeSteps', 'publishedVersion.activeSteps']);
        $draft = $campaign->draftVersion;
        $published = $campaign->publishedVersion;
        $version = $draft instanceof SequenceVersion
            ? $draft
            : ($published instanceof SequenceVersion ? $published : null);

        return SequenceStepResource::collection($version !== null ? $version->activeSteps : collect())->response();
    }

    public function update(UpdateSequenceStepRequest $request, SequenceStep $step, SequenceStepEditor $editor): SequenceStepResource
    {
        $this->authorize('update', $step);

        $data = $request->validated();
        if (isset($data['send_as'])) {
            $data['send_as'] = SequenceSendAs::from($data['send_as']);
        }

        try {
            $step = $editor->update($step, $data);
        } catch (GovernedCampaignInputMutation $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $this->failSequence($exception);
        }

        return new SequenceStepResource($step);
    }

    public function destroy(SequenceStep $step, SequenceStepEditor $editor): Response
    {
        $this->authorize('delete', $step);

        try {
            $editor->delete($step);
        } catch (GovernedCampaignInputMutation $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $this->failSequence($exception);
        }

        return response()->noContent();
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
            throw ValidationException::withMessages([
                $exception->field => $exception->getMessage(),
            ]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['template' => $exception->getMessage()]);
        }

        return response()->json([
            'subject' => $rendered['subject'],
            'body' => $rendered['body'],
            'to' => $recipient->email_address,
            'recipient_id' => $recipient->id,
        ]);
    }

    public function test(TestSequenceStepRequest $request, SequenceStep $step, TestSequenceStep $tester): JsonResponse
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
            $this->failSenderIdentity();
        } catch (InvalidSequenceTemplate $exception) {
            throw ValidationException::withMessages([
                $exception->field => $exception->getMessage(),
            ]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['template' => $exception->getMessage()]);
        }

        return response()->json(['sent' => true]);
    }
}
