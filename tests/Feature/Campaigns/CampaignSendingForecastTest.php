<?php

use App\Domain\Campaigns\Services\CampaignSendingForecast;
use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Campaigns\Services\SaveCampaignComposition;
use App\Domain\Objectives\Services\CreateExecutionAuthorityScope;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\SendAttempt;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

function sendingForecastFixture(int $people = 3, int $cap = 2, int $allowance = 20): array
{
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Capacity check');
    $mailbox = Mailbox::factory()->create(['user_id' => $owner->id, 'workspace_id' => $campaign->workspace_id, 'daily_send_cap' => $cap]);
    $campaign->mailboxes()->attach($mailbox->id, ['position' => 1]);
    CampaignRecipient::factory()->count($people)->create(['campaign_id' => $campaign->id, 'mailbox_id' => $mailbox->id]);
    $scope = app(CreateExecutionAuthorityScope::class)->handle($owner->currentWorkspace(), $owner, [
        'name' => 'Capacity allowance', 'operation_id' => (string) Str::uuid(), 'authority' => [
            'starts_at' => now()->toAtomString(), 'ends_at' => now()->addDays(10)->toAtomString(),
            'maximum_people' => $allowance, 'maximum_accounts' => $allowance, 'maximum_messages' => $allowance,
            'maximum_ai_cost_micros' => 0, 'approval_required' => true,
        ],
    ]);
    $composition = app(SaveCampaignComposition::class)->handle($campaign, $owner, [
        'objective_goal_version_id' => null, 'offer_version_id' => null, 'execution_authority_scope_id' => $scope->id,
        'sending_ends_at' => now()->setTime(17, 0)->toAtomString(),
        'expected_revision' => 0, 'operation_id' => (string) Str::uuid(),
    ]);

    return compact('owner', 'campaign', 'mailbox', 'composition');
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-07 10:00:00 UTC'));
    config()->set('outboss.sending.spacing_max_seconds', 180);
});

it('paces the whole campaign at the requested daily target', function () {
    $f = sendingForecastFixture(3, 50);
    $f['campaign']->forceFill(['daily_send_limit' => 1])->save();
    $forecast = app(CampaignSendingForecast::class)->handle($f['campaign'], $f['composition']);
    expect($forecast['daily_capacity'])->toBe(1)->and($forecast['inbox_daily_capacity'])->toBe(50)
        ->and($forecast['messages_by_deadline'])->toBe(1)->and($forecast['status'])->toBe('over_capacity')
        ->and(Carbon::parse($forecast['estimated_finish_at'])->toDateString())->toBe('2026-09-09');
});

it('explains when the requested daily pace exceeds selected inbox limits', function () {
    $f = sendingForecastFixture(3, 2);
    $f['campaign']->forceFill(['daily_send_limit' => 5])->save();
    $forecast = app(CampaignSendingForecast::class)->handle($f['campaign'], $f['composition']);
    expect($forecast['status'])->toBe('daily_limit_exceeded')->and($forecast['daily_capacity'])->toBe(2)
        ->and($forecast['message'])->toContain('5 messages/day')->and($f['mailbox']->fresh()->daily_send_cap)->toBe(2);
});

it('reports a deadline shortfall and estimates completion without changing caps or dates', function () {
    $f = sendingForecastFixture();
    $forecast = app(CampaignSendingForecast::class)->handle($f['campaign'], $f['composition']);
    expect($forecast['status'])->toBe('over_capacity')->and($forecast['shortfall'])->toBe(1)
        ->and($forecast['messages_by_deadline'])->toBe(2)
        ->and(Carbon::parse($forecast['estimated_finish_at'])->toDateString())->toBe('2026-09-08')
        ->and($f['mailbox']->fresh()->daily_send_cap)->toBe(2);
    $f['composition']->sending_ends_at = null;
    expect(app(CampaignSendingForecast::class)->handle($f['campaign'], $f['composition'])['status'])->toBe('fits');
});

it('distinguishes insufficient shared allowance from mailbox scheduling capacity', function () {
    $f = sendingForecastFixture(3, 100, 2);
    $forecast = app(CampaignSendingForecast::class)->handle($f['campaign'], $f['composition']);
    expect($forecast['status'])->toBe('allowance_exceeded')
        ->and($forecast['allowance']['quantities']['messages'])->toBe(['maximum' => 2, 'committed' => 0, 'remaining' => 2, 'projected' => 3]);
});

it('accounts for existing active work sharing the same inbox', function () {
    $f = sendingForecastFixture(1, 2);
    $other = app(CreateCampaignService::class)->handle($f['owner'], 'Existing active work');
    $other->forceFill(['status' => 'active'])->save();
    CampaignRecipient::factory()->count(2)->create(['campaign_id' => $other->id, 'mailbox_id' => $f['mailbox']->id]);
    $forecast = app(CampaignSendingForecast::class)->handle($f['campaign'], $f['composition']);
    expect($forecast['competing_messages'])->toBe(2)->and($forecast['status'])->toBe('over_capacity');
});

it('counts a reserved competing step once while preserving other reservations', function (string $otherStatus, string $expected) {
    $f = sendingForecastFixture(1, 2);
    $other = app(CreateCampaignService::class)->handle($f['owner'], 'Reserved work');
    $other->forceFill(['status' => $otherStatus])->save();
    $recipient = CampaignRecipient::factory()->create(['campaign_id' => $other->id, 'mailbox_id' => $f['mailbox']->id]);
    SendAttempt::factory()->create([
        'workspace_id' => $f['campaign']->workspace_id, 'mailbox_id' => $f['mailbox']->id,
        'campaign_recipient_id' => $recipient->id, 'sequence_step_id' => $recipient->next_sequence_step_id,
        'kind' => 'campaign', 'status' => 'queued', 'due_at' => now(),
    ]);
    $forecast = app(CampaignSendingForecast::class)->handle($f['campaign'], $f['composition']);
    expect($forecast['status'])->toBe($expected)->and($forecast['messages_by_deadline'])->toBe(1);
    // A paused campaign's reservation is not simulated, but still consumes capacity.
    $f['mailbox']->forceFill(['daily_send_cap' => 1])->save();
    expect(app(CampaignSendingForecast::class)->handle($f['campaign']->fresh(), $f['composition'])['status'])->toBe('over_capacity');
})->with([['active', 'fits'], ['paused', 'fits']]);

it('respects business days and exact deadline time', function () {
    $f = sendingForecastFixture(3, 2);
    $f['composition']->sending_starts_at = Carbon::parse('2026-09-11 16:59:00 UTC');
    $f['composition']->sending_ends_at = Carbon::parse('2026-09-11 17:00:00 UTC');
    $forecast = app(CampaignSendingForecast::class)->handle($f['campaign'], $f['composition']);
    expect($forecast['status'])->toBe('over_capacity')->and($forecast['messages_by_deadline'])->toBe(1)
        ->and(Carbon::parse($forecast['estimated_finish_at'])->toDateString())->toBe('2026-09-14');
});

it('does not promise capacity for paused or unselected assigned inboxes', function () {
    $f = sendingForecastFixture();
    $f['mailbox']->forceFill(['sending_paused_at' => now()])->save();
    expect(app(CampaignSendingForecast::class)->handle($f['campaign'], $f['composition'])['status'])->toBe('incomplete');
});

it('does not treat unsupported follow-ups as a launchable human sequence', function () {
    $f = sendingForecastFixture();
    $f['campaign']->draftVersion->activeSteps->sole()->forceFill(['delay_minutes' => 1440])->save();
    expect(app(CampaignSendingForecast::class)->handle($f['campaign']->fresh(), $f['composition'])['status'])->toBe('unsupported');
});

it('uses added inbox capacity only after explicit redistribution and preserves pinned mode', function () {
    $f = sendingForecastFixture(4, 2);
    $second = Mailbox::factory()->create(['user_id' => $f['owner']->id, 'workspace_id' => $f['campaign']->workspace_id, 'daily_send_cap' => 2]);
    $f['campaign']->mailboxes()->attach($second->id, ['position' => 2]);
    expect(app(CampaignSendingForecast::class)->handle($f['campaign']->fresh(), $f['composition'])['status'])->toBe('over_capacity');
    $this->actingAs($f['owner'])->post('/campaigns/'.$f['campaign']->id.'/redistribute-inboxes')->assertSessionHasNoErrors();
    expect($f['campaign']->recipients()->where('mailbox_id', $second->id)->count())->toBe(2)
        ->and(app(CampaignSendingForecast::class)->handle($f['campaign']->fresh(), $f['composition'])['status'])->toBe('fits');
    $f['campaign']->forceFill(['send_mode' => 'pin'])->save();
    $this->post('/campaigns/'.$f['campaign']->id.'/redistribute-inboxes')->assertSessionHasErrors('sending');
    expect($f['campaign']->recipients()->where('mailbox_id', $second->id)->count())->toBe(2);
});

it('denies redistribution from a different workspace', function () {
    $f = sendingForecastFixture();
    $this->actingAs(User::factory()->create())->post('/campaigns/'.$f['campaign']->id.'/redistribute-inboxes')->assertForbidden();
});

it('retains rolling domain history when inboxes are forecast out of timestamp order', function () {
    config()->set('outboss.sending.domain_hourly_limit', 3);
    $f = sendingForecastFixture(6, 3);
    $second = Mailbox::factory()->create(['user_id' => $f['owner']->id, 'workspace_id' => $f['campaign']->workspace_id, 'daily_send_cap' => 50]);
    $f['campaign']->mailboxes()->attach($second->id, ['position' => 2]);
    foreach ($f['campaign']->recipients()->orderBy('id')->get() as $index => $recipient) {
        $recipient->forceFill(['email_domain' => 'same.example', 'mailbox_id' => $index < 4 ? $f['mailbox']->id : $second->id])->save();
    }
    $f['composition']->sending_ends_at = now()->addMinutes(30);
    $forecast = app(CampaignSendingForecast::class)->handle($f['campaign']->fresh(), $f['composition']);
    expect($forecast['messages_by_deadline'])->toBe(3)->and($forecast['shortfall'])->toBe(3);
});

it('reports unavailable rather than truncating an oversized workload into a false fit', function () {
    $f = sendingForecastFixture(CampaignSendingForecast::MAX_FORECAST_WORK_ITEMS + 1, 1000);
    $forecast = app(CampaignSendingForecast::class)->handle($f['campaign'], $f['composition']);
    expect($forecast['status'])->toBe('unavailable')->and($forecast['estimated_finish_at'])->toBeNull()
        ->and($forecast['messages'])->toBe(CampaignSendingForecast::MAX_FORECAST_WORK_ITEMS + 1);
});
