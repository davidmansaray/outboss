<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Http\Resources\Api\V1\CampaignResource;
use App\Models\Campaign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Updates a campaign name, send mode (rotate or pin), attached mailbox_ids, or open-tracking flag. Sending setup can only change while the campaign is draft or paused. Does not send email.')]
class UpdateCampaign extends OutbossTool
{
    public function __construct(private readonly GovernedCampaignInputGuard $governedInputs) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()
                ->required()
                ->description('The campaign to update.'),
            'name' => $schema->string()
                ->description('New campaign name, up to 120 characters.'),
            'send_mode' => $schema->string()
                ->enum(SendMode::class)
                ->description('rotate shares sends across mailboxes; pin uses a single mailbox.'),
            'mailbox_ids' => $schema->array()
                ->items($schema->integer())
                ->unique()
                ->description('Workspace mailbox ids to attach, in send order. Unknown ids are ignored.'),
            'open_tracking_enabled' => $schema->boolean()
                ->description('Whether open tracking pixels are inserted into campaign mail.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsWrite->value)) {
            return $denied;
        }

        $data = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:120'],
            'send_mode' => ['sometimes', 'in:rotate,pin'],
            'mailbox_ids' => ['sometimes', 'array'],
            'mailbox_ids.*' => ['integer'],
            'open_tracking_enabled' => ['sometimes', 'boolean'],
        ]);

        $campaign = $this->authorised($request, Campaign::class, (int) $data['campaign_id'], 'update');
        try {
            $this->governedInputs->assertCampaignMutable($campaign);
            $this->assertSendingSetupEditable($campaign, $data);

            if (isset($data['name'])) {
                $campaign->forceFill(['name' => $data['name']])->save();
            }

            if (isset($data['send_mode'])) {
                $campaign->forceFill(['send_mode' => SendMode::from($data['send_mode'])])->save();
            }

            if (array_key_exists('open_tracking_enabled', $data)) {
                $campaign->forceFill(['open_tracking_enabled' => (bool) $data['open_tracking_enabled']])->save();
            }

            if (isset($data['mailbox_ids'])) {
                $workspace = $this->actor($request)->currentWorkspace();
                $owned = $workspace->mailboxes()->whereIn('id', $data['mailbox_ids'])->pluck('id');
                /** @var array<int, int|string> $mailboxIds */
                $mailboxIds = $data['mailbox_ids'];
                $ordered = collect($mailboxIds)
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn (int $id) => $owned->contains($id))
                    ->unique()
                    ->values();
                $campaign->mailboxes()->sync(
                    $ordered->mapWithKeys(fn ($id, $index) => [$id => ['position' => $index + 1]])->all()
                );
            }
        } catch (GovernedCampaignInputMutation $exception) {
            return $this->governedCampaignError($exception);
        }

        $campaign->refresh()->load([
            'draftVersion.activeSteps',
            'publishedVersion.activeSteps',
            'mailboxes',
        ]);

        return $this->json((new CampaignResource($campaign))->resolve());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertSendingSetupEditable(Campaign $campaign, array $data): void
    {
        $changingSendingSetup = array_key_exists('send_mode', $data)
            || array_key_exists('mailbox_ids', $data)
            || array_key_exists('open_tracking_enabled', $data);

        if (! $changingSendingSetup || $this->allowsEditing($campaign->getAttribute('status'))) {
            return;
        }

        throw ValidationException::withMessages([
            'campaign_id' => 'Pause the campaign before changing the sending setup.',
        ]);
    }

    private function allowsEditing(mixed $status): bool
    {
        if ($status instanceof CampaignStatus) {
            return $status->allowsEditing();
        }

        return in_array($status, [CampaignStatus::Draft->value, CampaignStatus::Paused->value], true);
    }
}
