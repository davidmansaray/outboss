<?php

use App\Domain\Mail\Services\FakeDomainAuthChecker;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('returns the cached domain-auth check for a workspace mailbox', function () {
    FakeDomainAuthChecker::$results['outboss.test'] = [
        'spf' => 'missing',
        'dmarc' => 'missing',
        'dkim' => 'unknown',
    ];
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'email_address' => 'david@outboss.test',
    ]);

    $this->actingAs($user)
        ->getJson(route('mailboxes.auth-check', $mailbox))
        ->assertOk()
        ->assertJson([
            'domain' => 'outboss.test',
            'spf' => 'missing',
            'dmarc' => 'missing',
            'dkim' => 'unknown',
        ]);

    FakeDomainAuthChecker::$results['outboss.test'] = [
        'spf' => 'pass',
        'dmarc' => 'pass',
        'dkim' => 'unknown',
    ];

    $this->actingAs($user)
        ->getJson(route('mailboxes.auth-check', $mailbox))
        ->assertJson([
            'spf' => 'missing',
            'dmarc' => 'missing',
        ]);

    expect(Cache::has('domain-auth:outboss.test'))->toBeTrue();
});

it('forbids domain-auth checks across workspaces', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($stranger)
        ->getJson(route('mailboxes.auth-check', $mailbox))
        ->assertForbidden();
});
