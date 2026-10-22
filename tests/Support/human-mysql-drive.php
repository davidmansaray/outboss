<?php

use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Campaigns\Services\PrepareHumanCampaignReview;
use App\Domain\Campaigns\Services\SaveCampaignComposition;
use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Domain\Campaigns\Services\SendCampaignMessage;
use App\Domain\Conversions\Services\InitializeMeetingMeasurement;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Domain\Objectives\Services\ApproveExecutionPlan;
use App\Domain\Objectives\Services\CompileExecutionPlan;
use App\Domain\Objectives\Services\CreateExecutionAuthorityScope;
use App\Domain\Objectives\Services\LaunchObjectiveExecution;
use App\Domain\Objectives\Services\SaveObjectiveGoal;
use App\Domain\Objectives\Support\ApprovalManifest;
use App\Models\CampaignRecipient;
use App\Models\ConversionDefinition;
use App\Models\ExecutionPlan;
use App\Models\Mailbox;
use App\Models\Person;
use App\Models\SendAttempt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Deliberately accepts only a local, clearly named disposable database. Never
// migrates, truncates or drops a database; initialize it separately and explicitly.
$database = $argv[1] ?? '';
$operation = $argv[2] ?? 'proof';
if (! preg_match('/^outboss_human_validation_\d{8}_\d{4}$/D', $database)
    || ! in_array($operation, ['seed', 'approve', 'compile', 'compile-stale', 'launch', 'launch-stale', 'schedule', 'schedule-stale', 'send', 'proof'], true)) {
    throw new RuntimeException('Usage: php tests/Support/human-mysql-drive.php outboss_human_validation_YYYYMMDD_HHMM [seed|approve|compile|compile-stale|launch|launch-stale|schedule|schedule-stale|send|proof]');
}
foreach ([
    'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => sys_get_temp_dir().'/'.$database.'_config.php',
    'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
    'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_SOCKET' => '', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database', 'SESSION_DRIVER' => 'array',
    'OUTBOSS_TEST_FAKE_INTELLIGENCE_PROVIDERS' => 'true', 'CAMPAIGN_SENDING_ENABLED' => 'true',
    'OUTBOSS_SEND_SPACING_MIN_SECONDS' => '0', 'OUTBOSS_SEND_SPACING_MAX_SECONDS' => '0',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class.': '.$error->getMessage().PHP_EOL);
    exit(1);
});
if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
    || DB::connection()->getDatabaseName() !== $database) {
    throw new RuntimeException('Refusing to run outside the disposable local MySQL fixture.');
}
foreach (MailProviderName::cases() as $provider) {
    if (! app(ProviderRegistry::class)->transport($provider) instanceof FakeMailProvider) {
        throw new RuntimeException('Every mail transport must be a test fake.');
    }
}
Carbon::setTestNow('2026-09-07T10:00:00+00:00');
CarbonImmutable::setTestNow('2026-09-07T10:00:00+00:00');
Queue::fake();
FakeMailProvider::reset();
if ($operation === 'seed') {
    if (User::query()->exists() || ExecutionPlan::query()->exists()) {
        throw new RuntimeException('Seed requires an empty disposable database.');
    }
    require_once __DIR__.'/ObjectiveScenarioFixtures.php';
    $fixture = s3IncrementThreeReadyFixture(startDelayMinutes: 0);
    $owner = $fixture['owner'];
    $owner->forceFill(['email' => 'human-mysql-validation@outboss.test'])->save();
    $workspace = $owner->currentWorkspace();
    app(InitializeMeetingMeasurement::class)->handle($workspace, $owner);
    $definition = ConversionDefinition::query()->where('workspace_id', $workspace->id)->where('event_key', 'meeting_booked')->sole();
    $goal = app(SaveObjectiveGoal::class)->handle($workspace, $owner, [
        'name' => 'MySQL human meetings', 'measurement' => 'meeting_booked', 'creation_key' => 'mysql-human-goal',
        'conversion_definition_version_id' => $definition->current_version_id,
    ]);
    $scope = app(CreateExecutionAuthorityScope::class)->handle($workspace, $owner, [
        'name' => 'One exact MySQL message', 'operation_id' => (string) Str::uuid(), 'authority' => [
            'starts_at' => now()->toAtomString(), 'ends_at' => now()->addDay()->toAtomString(),
            'maximum_people' => 1, 'maximum_accounts' => 1, 'maximum_messages' => 1, 'maximum_ai_cost_micros' => 0, 'approval_required' => true,
        ],
    ]);
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Human MySQL concurrency validation');
    $composition = app(SaveCampaignComposition::class)->handle($campaign, $owner, [
        'objective_goal_version_id' => $goal->current_goal_version_id, 'offer_version_id' => $fixture['version']->offer_version_id,
        'execution_authority_scope_id' => $scope->id, 'sending_starts_at' => now()->toAtomString(), 'sending_ends_at' => now()->addDay()->toAtomString(),
        'expected_revision' => 0, 'operation_id' => (string) Str::uuid(),
    ]);
    $person = Person::query()->where('workspace_id', $workspace->id)->firstOrFail();
    $mailbox = Mailbox::query()->where('workspace_id', $workspace->id)->whereNotNull('selected_sender_identity_key')->firstOrFail();
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id, 'person_id' => $person->id, 'account_id' => $person->account_id,
        'email_address' => $person->email_address, 'email_normalized' => $person->email_normalized,
        'mailbox_id' => $mailbox->id, 'first_name' => 'Alex', 'company' => 'Example',
    ]);
    $campaign->draftVersion->activeSteps->sole()->forceFill([
        'subject_template' => 'Hello {{first_name}}', 'body_template' => 'Would a consultation help {{company}}?',
        'subject_spintax_enabled' => false, 'body_spintax_enabled' => false,
    ])->save();
    app(PrepareHumanCampaignReview::class)->handle($campaign, $owner, $composition->revision, (string) Str::uuid());
}
$owner = User::query()->where('email', 'human-mysql-validation@outboss.test')->sole();
if (User::query()->count() !== 1) {
    throw new RuntimeException('This is not the dedicated single-owner fixture.');
}
$plan = ExecutionPlan::query()->where('origin', 'human')->sole();
$manifest = app(ApprovalManifest::class);
// Deliberately establish an old MySQL read view before the competing writer.
// Start this worker first, then its ordinary counterpart during this short wait.
$staleSnapshot = str_ends_with($operation, '-stale');
if ($staleSnapshot) {
    DB::beginTransaction();
    DB::table('execution_plan_campaigns')->count();
    DB::table('send_attempts')->count();
    usleep(2000000);
}
$result = match (str_replace('-stale', '', $operation)) {
    'approve' => app(ApproveExecutionPlan::class)->handle($plan, $owner, $plan->plan_hash, $manifest->hash($plan), $manifest->actionFingerprints($plan)),
    'compile' => app(CompileExecutionPlan::class)->handle($plan, $owner),
    'launch' => app(LaunchObjectiveExecution::class)->handle($plan, $owner),
    'schedule' => app(ScheduleDueCampaignSends::class)->handle(),
    'send' => app(SendCampaignMessage::class)->handle(SendAttempt::query()->sole()->id),
    default => null,
};
if ($staleSnapshot) {
    DB::commit();
}
if (str_replace('-stale', '', $operation) === 'launch'
    && (! $result instanceof ExecutionPlan || $result->status->value !== 'executing'
        || $result->compiledCampaigns->contains(fn ($mapping) => $mapping->campaign->status->value !== 'active'))) {
    throw new RuntimeException('Launch returned a stale execution projection.');
}
echo json_encode([
    'database' => $database, 'operation' => $operation, 'plan_status' => $plan->fresh()->status->value,
    'grants' => DB::table('approval_grants')->count(), 'campaigns' => DB::table('campaigns')->count(),
    'compilations' => DB::table('execution_plan_compilations')->where('state', 'succeeded')->count(),
    'attempts' => DB::table('send_attempts')->count(), 'sent_attempts' => DB::table('send_attempts')->where('status', 'sent')->count(),
    'messages' => DB::table('messages')->count(), 'fake_sends_this_process' => count(FakeMailProvider::$store['sends'] ?? []),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
