<?php

use App\Domain\Api\TokenScope;
use App\Domain\Mail\Enums\SuppressionReason;
use App\Domain\Workspaces\Services\WorkspaceExportService;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\SuppressionEntry;
use App\Models\User;

it('rejects suppressions without a token', function () {
    $this->getJson('/api/v1/suppressions')
        ->assertUnauthorized()
        ->assertJsonPath('error.type', 'unauthenticated');
});

it('rejects suppression writes when the token only has suppressions:read', function () {
    $user = User::factory()->create();

    $this->withToken(apiToken($user, [TokenScope::SuppressionsRead->value]))
        ->postJson('/api/v1/suppressions', ['email' => 'maya@stripe.com'])
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');
});

it('lists, adds, and deletes a suppression', function () {
    $user = User::factory()->create();
    $token = apiToken($user, [TokenScope::SuppressionsRead->value, TokenScope::SuppressionsWrite->value]);

    $created = $this->withToken($token)
        ->postJson('/api/v1/suppressions', ['email' => 'Maya@Stripe.com'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'Maya@Stripe.com')
        ->assertJsonPath('data.reason', 'manual');

    $this->withToken($token)
        ->getJson('/api/v1/suppressions')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->withToken($token)
        ->deleteJson('/api/v1/suppressions/'.$created->json('data.id'))
        ->assertNoContent();
});

it('returns a validation error for an invalid suppression email', function () {
    $user = User::factory()->create();

    $this->withToken(apiToken($user, [TokenScope::SuppressionsWrite->value]))
        ->postJson('/api/v1/suppressions', ['email' => 'not-valid'])
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'validation_error');
});

it('hides another workspace suppression', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $entry = SuppressionEntry::factory()->create([
        'user_id' => $owner->id,
        'reason' => SuppressionReason::Manual,
    ]);

    $this->withToken(apiToken($intruder, [TokenScope::SuppressionsWrite->value]))
        ->deleteJson('/api/v1/suppressions/'.$entry->id)
        ->assertForbidden();
});

it('rejects exports without a token', function () {
    $this->getJson('/api/v1/exports/enrollments')
        ->assertUnauthorized()
        ->assertJsonPath('error.type', 'unauthenticated');
});

it('rejects exports when the token lacks exports:read', function () {
    $user = User::factory()->create();

    $this->withToken(apiToken($user, [TokenScope::CampaignsRead->value]))
        ->getJson('/api/v1/exports/enrollments')
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');
});

it('exports bounded S2 sections only to an owner with export scope', function (): void {
    $owner = User::factory()->create();
    $token = apiToken($owner, [TokenScope::ExportsRead->value]);

    $this->withToken($token)
        ->getJson('/api/v1/exports/s2/research_runs')
        ->assertOk()
        ->assertJsonPath('meta.limit', 1000)
        ->assertJsonPath('meta.truncated', false);

    $this->withToken($token)
        ->getJson('/api/v1/exports/s2/not_a_section')
        ->assertNotFound();
});

it('returns enrolment, suppression, and campaign-outcome exports as json or csv', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id, 'name' => 'Founders']);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
    ]);
    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'email_address' => 'gone@acme.test',
        'email_normalized' => 'gone@acme.test',
    ]);
    $token = apiToken($user, [TokenScope::ExportsRead->value]);

    $this->withToken($token)
        ->getJson('/api/v1/exports/enrollments')
        ->assertOk()
        ->assertJsonPath('data.0.email', 'maya@stripe.com');

    $this->withToken($token)
        ->getJson('/api/v1/exports/suppressions')
        ->assertOk()
        ->assertJsonPath('data.0.email', 'gone@acme.test');

    $this->withToken($token)
        ->getJson('/api/v1/exports/campaign-outcomes')
        ->assertOk()
        ->assertJsonPath('data.0.campaign', 'Founders');

    $csv = $this->withToken($token)
        ->get('/api/v1/exports/enrollments?format=csv', ['Accept' => 'text/csv']);
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv')
        ->and($csv->streamedContent())->toContain('maya@stripe.com');
});

it('stops materialising export records at the requested boundary', function () {
    $visited = 0;
    $rows = (function () use (&$visited): iterable {
        for ($index = 0; $index < 1002; $index++) {
            $visited++;
            yield [$index];
        }
    })();

    $records = app(WorkspaceExportService::class)->records(['id'], $rows, 1001);

    expect($records)->toHaveCount(1001)
        ->and($visited)->toBe(1001);
});
