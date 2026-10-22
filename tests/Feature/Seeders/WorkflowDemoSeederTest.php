<?php

use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Mail\Enums\SuppressionReason;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Person;
use App\Models\SuppressionEntry;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UniboxDemoSeeder;
use Database\Seeders\WorkflowDemoSeeder;

it('seeds a workspace-scoped workflow fixture that can be re-run safely', function () {
    $this->seed(UniboxDemoSeeder::class);
    $this->seed(WorkflowDemoSeeder::class);
    $this->seed(WorkflowDemoSeeder::class);

    $user = User::query()->where('email', UniboxDemoSeeder::USER_EMAIL)->firstOrFail();
    $workspaceId = (int) $user->currentWorkspace()->id;
    $campaign = Campaign::query()
        ->where('workspace_id', $workspaceId)
        ->where('name', WorkflowDemoSeeder::CAMPAIGN_NAME)
        ->firstOrFail();

    $accountCount = Account::query()
        ->where('workspace_id', $workspaceId)
        ->where('domain_normalized', 'like', '%.workflow.demo')
        ->count();
    $personCount = Person::query()
        ->where('workspace_id', $workspaceId)
        ->where('email_normalized', 'like', '%@%.workflow.demo')
        ->count();
    $suppressionCount = SuppressionEntry::query()
        ->where('workspace_id', $workspaceId)
        ->where('email_normalized', 'like', '%@%.workflow.demo')
        ->count();
    $recipients = CampaignRecipient::query()
        ->where('workspace_id', $workspaceId)
        ->where('campaign_id', $campaign->id)
        ->get();
    $steps = $campaign->fresh('draftVersion.activeSteps')->draftVersion?->activeSteps ?? collect();

    expect($accountCount)->toBe(6)
        ->and($personCount)->toBe(20)
        ->and($suppressionCount)->toBe(2)
        ->and(SuppressionEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where('email_normalized', 'lea@lumen-health.workflow.demo')
            ->value('reason'))->toBe(SuppressionReason::Unsubscribe)
        ->and(SuppressionEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where('email_normalized', 'spam@atlas-robotics.workflow.demo')
            ->value('reason'))->toBe(SuppressionReason::Manual)
        ->and($recipients)->toHaveCount(8)
        ->and($recipients->whereNull('person_id')->count())->toBe(0)
        ->and($recipients->whereNull('account_id')->count())->toBe(0)
        ->and($steps->count())->toBeGreaterThanOrEqual(2)
        ->and($steps->firstWhere('delay_minutes', '>', 0))->not->toBeNull()
        ->and($steps->firstWhere('send_as', SequenceSendAs::Reply))->not->toBeNull();

    $this->seed(DatabaseSeeder::class);

    expect(Account::query()->where('workspace_id', $workspaceId)->where('domain_normalized', 'like', '%.workflow.demo')->count())->toBe(6)
        ->and(Person::query()->where('workspace_id', $workspaceId)->where('email_normalized', 'like', '%@%.workflow.demo')->count())->toBe(20)
        ->and(CampaignRecipient::query()->where('campaign_id', $campaign->id)->count())->toBe(8)
        ->and($campaign->fresh('draftVersion.activeSteps')->draftVersion?->activeSteps->count())->toBe($steps->count());
});
