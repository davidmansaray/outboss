<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Services\SequenceStepEditor;
use App\Http\Resources\Api\V1\SequenceStepResource;
use App\Models\Campaign;
use App\Models\SequenceVersion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use RuntimeException;

#[Description('Reorders the campaign draft sequence. Provide every active step id exactly once in the desired order. The campaign must be draft or paused. Does not send email.')]
class ReorderSequenceSteps extends OutbossTool
{
    public function __construct(private SequenceStepEditor $editor) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()
                ->required()
                ->description('The campaign whose sequence should be reordered.'),
            'step_ids' => $schema->array()
                ->items($schema->integer())
                ->min(1)
                ->unique()
                ->required()
                ->description('Active step ids in the new order. Must list every current step exactly once.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsWrite->value)) {
            return $denied;
        }

        $data = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'step_ids' => ['required', 'array', 'min:1'],
            'step_ids.*' => ['integer', 'distinct'],
        ]);
        $campaign = $this->authorised($request, Campaign::class, (int) $data['campaign_id'], 'update');

        try {
            /** @var list<int> $stepIds */
            $stepIds = array_values(array_map(intval(...), $data['step_ids']));
            $this->editor->reorder($campaign, $stepIds);
        } catch (RuntimeException $exception) {
            $this->failSequence($exception);
        }

        $campaign->refresh()->load(['draftVersion.activeSteps', 'publishedVersion.activeSteps']);
        $draft = $campaign->draftVersion;
        $published = $campaign->publishedVersion;
        $version = $draft instanceof SequenceVersion
            ? $draft
            : ($published instanceof SequenceVersion ? $published : null);

        return $this->json([
            'data' => SequenceStepResource::collection($version !== null ? $version->activeSteps : collect())->resolve(),
        ]);
    }
}
