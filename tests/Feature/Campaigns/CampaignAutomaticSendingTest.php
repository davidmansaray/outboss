<?php

use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Models\CampaignComposition;
use App\Models\CampaignRecipient;
use App\Models\ExecutionAuthorityScope;
use App\Models\Mailbox;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('saves pacing and prepares finite permission in one idempotent step without approval', function () {
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Automatic sending');
    $mailbox = Mailbox::factory()->create(['user_id' => $owner->id, 'workspace_id' => $campaign->workspace_id, 'daily_send_cap' => 50]);
    CampaignRecipient::factory()->count(2)->create(['campaign_id' => $campaign->id, 'mailbox_id' => null]);
    $input = ['daily_send_limit' => 20, 'mailbox_ids' => [$mailbox->id], 'automatic_allowance' => true];
    $this->actingAs($owner)->patch('/campaigns/'.$campaign->id, $input)->assertSessionHasNoErrors()->assertRedirect();
    $scope = ExecutionAuthorityScope::query()->sole();
    expect($scope->authority['maximum_messages'])->toBe(2)
        ->and($scope->authority['approval_required'])->toBeTrue()
        ->and($campaign->fresh()->daily_send_limit)->toBe(20)
        ->and($campaign->fresh()->automatic_sending_scope_id)->toBe($scope->id)
        ->and($campaign->recipients()->whereNull('mailbox_id')->count())->toBe(0);
    $this->patch('/campaigns/'.$campaign->id, $input)->assertSessionHasNoErrors();
    expect(ExecutionAuthorityScope::query()->count())->toBe(1)
        ->and($campaign->fresh()->launched_at)->toBeNull();
    $this->assertDatabaseCount('approval_grants', 0);
    $this->assertDatabaseCount('send_attempts', 0);
});

it('resizes only its own unapproved automatic allowance when the audience grows', function () {
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Growing draft');
    CampaignRecipient::factory()->create(['campaign_id' => $campaign->id]);
    $this->actingAs($owner)->patch('/campaigns/'.$campaign->id, ['daily_send_limit' => 10, 'automatic_allowance' => true])->assertSessionHasNoErrors();
    $original = $campaign->fresh()->automatic_sending_scope_id;
    CampaignRecipient::factory()->create(['campaign_id' => $campaign->id]);
    $this->patch('/campaigns/'.$campaign->id, ['daily_send_limit' => 10, 'automatic_allowance' => true])->assertSessionHasNoErrors();
    expect($campaign->fresh()->automatic_sending_scope_id)->not->toBe($original);
    expect(ExecutionAuthorityScope::findOrFail($original)->authority['maximum_messages'])->toBe(1);
    expect(ExecutionAuthorityScope::findOrFail($campaign->fresh()->automatic_sending_scope_id)->authority['maximum_messages'])->toBe(2);
});

it('rejects invalid daily limits without changing saved pacing', function () {
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Invalid pace');
    $this->actingAs($owner)->patch('/campaigns/'.$campaign->id, ['daily_send_limit' => 0])->assertSessionHasErrors('daily_send_limit');
    expect($campaign->fresh()->daily_send_limit)->toBeNull();
});

it('returns from inbox settings to the originating campaign without exposing other workspaces', function () {
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Return here');
    $this->actingAs($owner)->get('/settings/mailboxes?from_campaign='.$campaign->id)
        ->assertInertia(fn (Assert $page) => $page->where('returnCampaign.id', $campaign->id));
    $this->actingAs(User::factory()->create())->get('/settings/mailboxes?from_campaign='.$campaign->id)
        ->assertInertia(fn (Assert $page) => $page->where('returnCampaign', null));
});

it('balances draft recipients proportionally when inbox capacity differs', function () {
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Balanced sending');
    $small = Mailbox::factory()->create(['user_id' => $owner->id, 'workspace_id' => $campaign->workspace_id, 'daily_send_cap' => 1]);
    $large = Mailbox::factory()->create(['user_id' => $owner->id, 'workspace_id' => $campaign->workspace_id, 'daily_send_cap' => 9]);
    CampaignRecipient::factory()->count(10)->create(['campaign_id' => $campaign->id, 'mailbox_id' => $small->id]);
    $this->actingAs($owner)->patch('/campaigns/'.$campaign->id, ['daily_send_limit' => 10, 'mailbox_ids' => [$small->id, $large->id], 'automatic_allowance' => true])->assertSessionHasNoErrors();
    expect($campaign->recipients()->where('mailbox_id', $small->id)->count())->toBe(1)
        ->and($campaign->recipients()->where('mailbox_id', $large->id)->count())->toBe(9);
});

it('preserves an explicitly selected shared allowance rather than expanding it', function () {
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Shared limits');
    CampaignRecipient::factory()->create(['campaign_id' => $campaign->id]);
    $this->actingAs($owner)->patch('/campaigns/'.$campaign->id, ['daily_send_limit' => 10, 'automatic_allowance' => true])->assertSessionHasNoErrors();
    $scopeId = $campaign->fresh()->automatic_sending_scope_id;
    $campaign->fresh()->forceFill(['automatic_sending_scope_id' => null])->save();
    CampaignRecipient::factory()->create(['campaign_id' => $campaign->id]);
    $this->patch('/campaigns/'.$campaign->id, ['daily_send_limit' => 20, 'automatic_allowance' => true])->assertSessionHasNoErrors();
    expect(ExecutionAuthorityScope::query()->count())->toBe(1)
        ->and(ExecutionAuthorityScope::findOrFail($scopeId)->authority['maximum_messages'])->toBe(1);
});

it('rolls back settings when automatic permission exceeds server safety limits', function () {
    config()->set('objectives.safety.maximum_messages', 1);
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Too large');
    CampaignRecipient::factory()->count(2)->create(['campaign_id' => $campaign->id]);
    $this->actingAs($owner)->patch('/campaigns/'.$campaign->id, ['daily_send_limit' => 20, 'automatic_allowance' => true])->assertSessionHasErrors();
    expect($campaign->fresh()->daily_send_limit)->toBeNull();
    expect(CampaignComposition::query()->where('campaign_id', $campaign->id)->count())->toBe(1);
    expect(ExecutionAuthorityScope::query()->count())->toBe(0);
});
