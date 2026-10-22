<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Services\SequenceStepEditor;
use App\Http\Resources\Api\V1\SequenceStepResource;
use App\Models\SequenceStep;
use App\Rules\SequenceTemplateSpintax;
use App\Rules\SequenceTemplateVariables;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use RuntimeException;

#[Description('Updates a sequence step title, delay, send_as (new_thread or reply), subject, or body. Allowed delay_minutes: 0, 2880, 4320, 5760, 7200, 10080. Templates may use {{first_name}} and {{company}}, plus deterministic variations such as {Hi|Hello}. The campaign must be editable. Does not send email.')]
class UpdateSequenceStep extends OutbossTool
{
    public function __construct(private SequenceStepEditor $editor) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'step_id' => $schema->integer()
                ->required()
                ->description('The sequence step to update.'),
            'title' => $schema->string()
                ->description('Step title, up to 80 characters.'),
            'delay_minutes' => $schema->integer()
                ->enum([0, 2880, 4320, 5760, 7200, 10080])
                ->description('Delay before this step: 0 (immediate), 2 days, 3 days, 4 days, 5 days, or 7 days.'),
            'send_as' => $schema->string()
                ->enum(SequenceSendAs::class)
                ->description('new_thread starts a new thread and needs a subject; reply continues the existing thread.'),
            'subject_template' => $schema->string()
                ->description('Subject for new_thread steps. Use {{first_name}} and {{company}} if needed. Add stable per-recipient variations with {option one|option two}.'),
            'body_template' => $schema->string()
                ->description('Email body template, up to 10,000 characters. Add stable per-recipient variations with {option one|option two}.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsWrite->value)) {
            return $denied;
        }

        $data = $request->validate([
            'step_id' => ['required', 'integer'],
            'title' => ['sometimes', 'string', 'max:80'],
            'delay_minutes' => ['sometimes', 'integer', 'in:0,2880,4320,5760,7200,10080'],
            'send_as' => ['sometimes', 'in:new_thread,reply'],
            'subject_template' => ['nullable', 'string', 'max:240', new SequenceTemplateVariables, new SequenceTemplateSpintax],
            'body_template' => ['sometimes', 'string', 'max:10000', new SequenceTemplateVariables, new SequenceTemplateSpintax],
        ]);
        $step = $this->authorised($request, SequenceStep::class, (int) $data['step_id'], 'update');
        unset($data['step_id']);

        if (isset($data['send_as'])) {
            $data['send_as'] = SequenceSendAs::from($data['send_as']);
        }

        try {
            $step = $this->editor->update($step, $data);
        } catch (RuntimeException $exception) {
            $this->failSequence($exception);
        }

        return $this->json((new SequenceStepResource($step))->resolve());
    }
}
