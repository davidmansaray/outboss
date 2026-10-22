<?php

use App\Models\Campaign;
use App\Models\Mailbox;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\UniboxDemoSeeder;
use Database\Seeders\UserFlowBrowserSeeder;
use Database\Seeders\WorkflowDemoSeeder;

it('seeds the isolated user-flow browser fixture on sample data', function () {
    $this->seed(UserFlowBrowserSeeder::class);

    $user = User::query()->where('email', UniboxDemoSeeder::USER_EMAIL)->firstOrFail();
    $workspaceId = (int) $user->currentWorkspace()->id;

    expect(Mailbox::query()->where('workspace_id', $workspaceId)->count())->toBe(2)
        ->and(Person::query()->where('workspace_id', $workspaceId)->where('email_normalized', 'like', '%@%.workflow.demo')->count())->toBe(20)
        ->and(Campaign::query()->where('workspace_id', $workspaceId)->where('name', WorkflowDemoSeeder::CAMPAIGN_NAME)->exists())->toBeTrue();
});
