<?php

use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Domain\Campaigns\Services\SendCampaignMessage;
use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\DTOs\OAuthCallback;
use App\Domain\Mail\DTOs\RotatedCredential;
use App\Domain\Mail\Enums\AuthScheme;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Enums\OAuthIntent;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Services\ConnectMailboxService;
use App\Domain\Mail\Services\EnsureConnectionUsable;
use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Mail\Services\ManagedMailEventRecorder;
use App\Domain\Mail\Services\RotateManagedMailboxCredential;
use App\Domain\Objectives\Services\CompileExecutionPlan;
use App\Domain\Objectives\Services\LaunchObjectiveExecution;
use App\Domain\Objectives\Services\StartObjectiveRun;
use App\Models\ApprovalBatch;
use App\Models\ExecutionActionEvent;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\OAuthAttempt;
use App\Models\PolicyEvaluation;
use App\Models\ProviderConnection;
use App\Models\SendAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/ObjectiveScenarioFixtures.php';

uses(RefreshDatabase::class);

afterEach(function (): void {
    FakeMailProvider::reset();
});

function refreshExactApprovalOAuthToken(ProviderConnection $connection): ProviderConnection
{
    $connection->forceFill(['token_expires_at' => now()->subMinute()])->save();

    return app(EnsureConnectionUsable::class)->handle($connection->fresh());
}

it('keeps one exact grant valid through routine OAuth refreshes at every provider-boundary stage', function (): void {
    FakeMailProvider::reset();
    $this->travelTo(Carbon::parse('2026-09-07 10:00:00 UTC'));
    Config::set('outboss.campaign_sending_enabled', true);
    Queue::fake();

    $fixture = s3IncrementThreeReadyFixture(startDelayMinutes: 0);
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        'authorization-version-refresh-liveness',
    );
    $plan = approveS3PlanForCompilerFixture(runS3IncrementThreeToApproval($run));
    $action = $plan->actions->sole();
    $connection = ProviderConnection::query()->findOrFail($action->provider_connection_id);
    $approvedAuthorizationVersion = (int) $action->provider_connection_version;
    $initialTokenVersion = (int) $connection->token_version;

    $connection = refreshExactApprovalOAuthToken($connection);
    app(CompileExecutionPlan::class)->handle($plan, $fixture['owner']);

    $connection = refreshExactApprovalOAuthToken($connection);
    app(LaunchObjectiveExecution::class)->handle($plan->fresh(), $fixture['owner']);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);

    $connection = refreshExactApprovalOAuthToken($connection);
    $attempt = SendAttempt::query()->sole();
    app(SendCampaignMessage::class)->handle($attempt->id);

    expect($connection->token_version)->toBe($initialTokenVersion + 3)
        ->and($connection->authorization_version)->toBe($approvedAuthorizationVersion)
        ->and($action->provider_connection_version)->toBe($approvedAuthorizationVersion)
        ->and(ApprovalBatch::query()->sole()->state->value)->toBe('approved')
        ->and($attempt->fresh()->status->value)->toBe('sent')
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount(1)
        ->and(PolicyEvaluation::query()->where('stage', 'pre_send')->where('result', 'allowed')->count())->toBe(1);
});

it('invalidates an exact approval when a managed credential rotation replaces authority', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        'authorization-version-managed-rotation',
    );
    $plan = approveS3PlanForCompilerFixture(runS3IncrementThreeToApproval($run));
    $action = $plan->actions->sole();
    $mailbox = $action->mailbox()->firstOrFail();
    $connection = $mailbox->connection()->firstOrFail();
    $approvedAuthorizationVersion = (int) $action->provider_connection_version;

    config()->set('outboss.managed_mail.instances.internal', [
        'base_url' => 'https://mx-api.example.test',
        'admin_api_key' => 'test-only-secret',
    ]);
    $connection->forceFill([
        'provider' => MailProviderName::Stalwart,
        'connection_kind' => ConnectionKind::Managed,
        'auth_scheme' => AuthScheme::AppPassword,
        'managed_instance_key' => 'internal',
        'credential_payload' => [
            'username' => $mailbox->email_address,
            'password' => 'initial-managed-password',
            'version' => 1,
        ],
    ])->save();
    $domain = ManagedMailDomain::factory()->create([
        'workspace_id' => $plan->workspace_id,
        'created_by_user_id' => $fixture['owner']->id,
        'managed_instance_key' => 'internal',
    ]);
    $account = ManagedMailboxAccount::factory()->create([
        'managed_mail_domain_id' => $domain->id,
        'mailbox_id' => $mailbox->id,
        'remote_account_id' => $connection->provider_user_id,
        'local_part' => Str::before($mailbox->email_address, '@'),
        'credential_version' => 1,
        'status' => ManagedMailboxAccountStatus::Active,
    ]);
    $provisioner = Mockery::mock(ManagedMailboxProvisioner::class);
    $provisioner->shouldReceive('rotateMailboxCredential')
        ->once()
        ->andReturn(new RotatedCredential($mailbox->email_address, 'rotated-managed-password', 2));

    (new RotateManagedMailboxCredential(
        $provisioner,
        new ManagedMailCapability,
        new ManagedMailEventRecorder,
    ))->handle($fixture['owner']->currentWorkspace(), $fixture['owner'], $account);

    expect($connection->fresh()->authorization_version)->toBe($approvedAuthorizationVersion + 1)
        ->and(fn () => app(CompileExecutionPlan::class)->handle($plan, $fixture['owner']))
        ->toThrow(ValidationException::class)
        ->and(ApprovalBatch::query()->sole()->state->value)->toBe('invalidated');
});

it('rejects a reconnect after lease acquisition at pre-send without calling the provider', function (): void {
    FakeMailProvider::reset();
    $this->travelTo(Carbon::parse('2026-09-07 10:00:00 UTC'));
    Config::set('outboss.campaign_sending_enabled', true);
    $fixture = compiledS3GuardFixture();
    app(LaunchObjectiveExecution::class)->handle($fixture['plan'], $fixture['owner']);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);

    $attempt = SendAttempt::query()->sole();
    $connection = $fixture['mailbox']->connection()->firstOrFail();
    $approvedAuthorizationVersion = (int) $fixture['plan']->actions()->sole()->provider_connection_version;
    $provider = $connection->provider;
    FakeMailProvider::$forcedIdentity = (string) $connection->provider_user_id;
    FakeMailProvider::seedMailbox(
        $fixture['mailbox']->email_address,
        $provider,
        (string) $connection->provider_user_id,
    );
    $realConnections = app(EnsureConnectionUsable::class);
    $this->mock(EnsureConnectionUsable::class)
        ->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function (ProviderConnection $candidate) use ($realConnections, $fixture, $provider): ProviderConnection {
            $usable = $realConnections->handle($candidate);
            $rawState = 'reconnect-before-provider-'.Str::random(12);
            OAuthAttempt::query()->create([
                'user_id' => $fixture['owner']->id,
                'workspace_id' => $fixture['plan']->workspace_id,
                'provider' => $provider,
                'state_hash' => hash('sha256', $rawState),
                'pkce_verifier' => Str::random(64),
                'reconnect_connection_id' => $usable->id,
                'reconnect_mailbox_id' => $fixture['mailbox']->id,
                'intent' => OAuthIntent::ReconnectMailbox,
                'expires_at' => now()->addMinutes(10),
            ]);

            return app(ConnectMailboxService::class)->complete(
                $fixture['owner'],
                $provider,
                new OAuthCallback('ok', $rawState),
                $rawState,
            );
        });

    app(SendCampaignMessage::class)->handle($attempt->id);

    expect($connection->fresh()->authorization_version)->toBe($approvedAuthorizationVersion + 1)
        ->and($attempt->fresh()->status->value)->toBe('deferred')
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([])
        ->and(ExecutionActionEvent::query()->where('event', 'attempted')->count())->toBe(0)
        ->and(PolicyEvaluation::query()->where('stage', 'pre_send')->where('result', 'blocked')->count())->toBe(1)
        ->and(ApprovalBatch::query()->sole()->state->value)->toBe('invalidated');
});
