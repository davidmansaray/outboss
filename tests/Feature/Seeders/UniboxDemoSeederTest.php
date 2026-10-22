<?php

use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Person;
use App\Models\Thread;
use App\Models\User;
use Database\Seeders\UniboxDemoSeeder;

it('restores a repeatable unibox fixture across folders, campaigns, and mailboxes', function () {
    $this->seed(UniboxDemoSeeder::class);
    $this->seed(UniboxDemoSeeder::class);

    $user = User::query()->where('email', UniboxDemoSeeder::USER_EMAIL)->firstOrFail();
    $primary = Mailbox::query()->where('email_address', 'david@outboss.test')->firstOrFail();
    $founders = Campaign::query()->where('name', 'Q3 Series A founders')->firstOrFail();

    expect($user->mailboxes()->count())->toBe(2)
        ->and($user->campaigns()->count())->toBe(2)
        ->and(Thread::query()->where('user_id', $user->id)->where('remote_thread_id', 'like', 'demo:%')->count())->toBe(9)
        ->and(Message::query()->where('remote_message_id', 'like', 'demo:%')->count())->toBe(28)
        ->and(Thread::query()
            ->where('user_id', $user->id)
            ->where('remote_thread_id', 'demo:maya-stripe-interest')
            ->firstOrFail()
            ->messages()
            ->count())->toBe(4)
        ->and(Person::query()->where('workspace_id', $primary->getAttribute('workspace_id'))->count())->toBe(7)
        ->and(Account::query()->where('workspace_id', $primary->getAttribute('workspace_id'))->count())->toBe(7)
        ->and(CampaignRecipient::query()
            ->where('workspace_id', $primary->getAttribute('workspace_id'))
            ->whereNull('person_id')
            ->count())->toBe(0);

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'leads']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('unibox/Index')
            ->has('threads.data', 3)
            ->where('counts.leads', 3)
            ->where('counts.all_replies', 5)
            ->where('counts.closed', 3)
            ->where('counts.sent', 9)
            ->where('counts.ooo', 1)
            ->where('counts.delivery', 1)
            ->where('counts.opt_outs', 1)
            ->where('threads.data.0.person.name', 'Maya Chen')
            ->where('threads.data.0.account.name', 'Stripe'));

    $this->actingAs($user)
        ->get(route('unibox.index', [
            'folder' => 'leads',
            'campaign' => $founders->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('threads.data', 2)
            ->where('counts.leads', 2)
            ->where('counts.ooo', 1)
            ->where('counts.delivery', 1));

    $this->actingAs($user)
        ->get(route('unibox.index', [
            'folder' => 'all_replies',
            'mailbox' => $primary->id,
            'campaign' => 'none',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('threads.data', 1)
            ->where('threads.data.0.subject', 'Re: Introduction from Nina'));
});
