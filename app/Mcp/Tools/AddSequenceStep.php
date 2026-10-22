<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Services\SequenceStepEditor;
use App\Http\Resources\Api\V1\SequenceStepResource;
use App\Models\Campaign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use RuntimeException;

#[Description('Adds a follow-up step to the campaign draft sequence. The campaign must be draft or paused. Does not send email.')]
class AddSequenceStep extends OutbossTool
{
    public function __construct(private SequenceStepEditor $editor) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()
                ->required()
                ->description('The campaign that owns the sequence.'),
            'title' => $schema->string()
                ->description('Optional step title (maximum 80 characters). Defaults to Follow-up.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsWrite->value)) {
            return $denied;
        }

        $data = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:80'],
        ]);
        $campaign = $this->authorised($request, Campaign::class, (int) $data['campaign_id'], 'update');

        try {
            $step = $this->editor->add($campaign, is_string($data['title'] ?? null) && $data['title'] !== ''
                ? $data['title']
                : 'Follow-up');
        } catch (RuntimeException $exception) {
            $this->failSequence($exception);
        }

        return $this->json((new SequenceStepResource($step))->resolve());
    }
}
