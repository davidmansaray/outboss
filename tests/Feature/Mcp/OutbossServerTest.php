<?php

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Mcp\OutbossServer;
use App\Mcp\Prompts\CampaignReviewPrompt;
use App\Mcp\Prompts\TriageRepliesPrompt;
use App\Mcp\Resources\CampaignOutcomesResource;
use App\Mcp\Resources\MailboxHealthResource;
use App\Mcp\Tools\CreateCampaign;
use App\Mcp\Tools\GetCampaign;
use App\Mcp\Tools\LaunchCampaign;
use App\Mcp\Tools\ListCampaigns;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('lists campaigns for the authenticated workspace', function () {
    $user = User::factory()->create();
    Campaign::factory()->create(['user_id' => $user->id, 'name' => 'Alpha outreach']);
    Campaign::factory()->create(['user_id' => $user->id, 'name' => 'Beta follow-up']);

    OutbossServer::actingAs(mcpUser($user, [TokenScope::CampaignsRead->value]))
        ->tool(ListCampaigns::class)
        ->assertOk()
        ->assertSee(['Alpha outreach', 'Beta follow-up']);
});

it('creates agent-tool drafts with mandatory exact approval from creation', function () {
    $user = User::factory()->create();
    OutbossServer::actingAs(mcpUser($user, [TokenScope::CampaignsWrite->value]))
        ->tool(CreateCampaign::class, ['name' => 'Governed from creation'])
        ->assertOk();
    $campaign = Campaign::query()->sole();
    expect(CampaignComposition::query()->where('campaign_id', $campaign->id)->sole()->revision)->toBe(0);
    OutbossServer::actingAs(mcpUser($user, [TokenScope::CampaignsLaunch->value]))
        ->tool(LaunchCampaign::class, ['campaign_id' => $campaign->id])->assertHasErrors();
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft);
});

it('allows session usage without a token to call a read tool', function () {
    $user = User::factory()->create();
    Campaign::factory()->create(['user_id' => $user->id, 'name' => 'Session campaign']);

    OutbossServer::actingAs($user)
        ->tool(ListCampaigns::class)
        ->assertOk()
        ->assertSee('Session campaign');
});

it('refuses a read tool when the token lacks the required scope', function () {
    $user = User::factory()->create();
    Campaign::factory()->create(['user_id' => $user->id]);

    OutbossServer::actingAs(mcpUser($user, [TokenScope::PeopleRead->value]))
        ->tool(ListCampaigns::class)
        ->assertHasErrors(['Missing required scope: campaigns:read']);
});

it('launches a ready campaign when the token has campaigns:launch', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Queued,
    ]);

    OutbossServer::actingAs(mcpUser($user, [TokenScope::CampaignsLaunch->value]))
        ->tool(LaunchCampaign::class, ['campaign_id' => $campaign->id])
        ->assertOk()
        ->assertSee('active');

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Active);
});

it('refuses LaunchCampaign when the token lacks campaigns:launch', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    OutbossServer::actingAs(mcpUser($user, [TokenScope::CampaignsWrite->value]))
        ->tool(LaunchCampaign::class, ['campaign_id' => $campaign->id])
        ->assertHasErrors(['Missing required scope: campaigns:launch']);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft);
});

it('cannot read another workspace campaign', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $owner->id, 'name' => 'Secret pipeline']);

    OutbossServer::actingAs(mcpUser($intruder, [TokenScope::CampaignsRead->value]))
        ->tool(GetCampaign::class, ['campaign_id' => $campaign->id])
        ->assertHasErrors();
});

it('returns campaign outcomes and mailbox health resources', function () {
    $user = User::factory()->create();
    Campaign::factory()->create(['user_id' => $user->id, 'name' => 'Outcome campaign']);
    Mailbox::factory()->create(['user_id' => $user->id, 'email_address' => 'sender@outboss.test']);

    OutbossServer::actingAs(mcpUser($user, [
        TokenScope::CampaignsRead->value,
        TokenScope::MailboxesRead->value,
    ]))
        ->resource(CampaignOutcomesResource::class)
        ->assertOk()
        ->assertSee('Outcome campaign');

    OutbossServer::actingAs(mcpUser($user, [
        TokenScope::CampaignsRead->value,
        TokenScope::MailboxesRead->value,
    ]))
        ->resource(MailboxHealthResource::class)
        ->assertOk()
        ->assertSee('sender@outboss.test');
});

it('returns triage-replies and campaign-review prompts', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id, 'name' => 'Review me']);

    OutbossServer::actingAs(mcpUser($user, [TokenScope::InboxRead->value]))
        ->prompt(TriageRepliesPrompt::class)
        ->assertOk()
        ->assertName('triage-replies')
        ->assertSee('need attention');

    OutbossServer::actingAs(mcpUser($user, [TokenScope::CampaignsRead->value]))
        ->prompt(CampaignReviewPrompt::class, ['campaign_id' => $campaign->id])
        ->assertOk()
        ->assertName('campaign-review')
        ->assertSee('Review me');
});

it('rejects unauthenticated HTTP requests to the MCP endpoint', function () {
    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ],
    ])->assertUnauthorized();
});

it('rate limits the public MCP endpoint', function () {
    $route = Route::getRoutes()->match(Request::create('/mcp', 'POST'));
    $middleware = $route->gatherMiddleware();
    $positions = array_flip($middleware);

    expect($middleware)
        ->toContain('throttle:ingress')
        ->toContain('auth:sanctum')
        ->toContain('throttle:mcp')
        ->and($positions['throttle:ingress'])
        ->toBeLessThan($positions['auth:sanctum']);
});
