<?php

use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Domain\Campaigns\Services\SendCampaignMessage;
use App\Domain\Conversions\Services\IngestConversionEvent;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Services\ApplyInboundCampaignEffects;
use App\Domain\Objectives\Services\AssessObjectiveAttribution;
use App\Domain\Objectives\Services\LaunchObjectiveExecution;
use App\Domain\Objectives\Services\ProjectGtmConversation;
use App\Domain\Objectives\Services\RebuildObjectiveProgress;
use App\Domain\Objectives\Services\RecordObjectiveExposure;
use App\Models\ApprovalGrant;
use App\Models\AttributionAssessment;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ConversionEvent;
use App\Models\ExecutionPlan;
use App\Models\Exposure;
use App\Models\GtmConversation;
use App\Models\Message;
use App\Models\ObjectiveOutcomeProgress;
use App\Models\SendAttempt;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$database = realpath($argv[1] ?? '');
$operation = $argv[2] ?? 'proof';
if ($database === false || basename($database) !== 's3-browser.sqlite'
    || ! str_starts_with(basename(dirname($database)), 'outboss-s3-browser.')
    || ! in_array($operation, ['proof', 'launch', 'send', 'reply', 'conversion'], true)) {
    throw new RuntimeException('Usage: php tests/Browser/s3-drive.php <disposable-s3-browser.sqlite> [proof|launch|send|reply|conversion]');
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_CONFIG_CACHE' => dirname($database).'/config.php',
    'DB_CONNECTION' => 'sqlite',
    'DB_URL' => '',
    'DB_DATABASE' => $database,
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'database',
    'OUTBOSS_TEST_FAKE_INTELLIGENCE_PROVIDERS' => 'true',
    'CAMPAIGN_SENDING_ENABLED' => 'true',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/s3-clock.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite'
    || realpath((string) config('database.connections.sqlite.database')) !== $database
    || User::query()->count() !== 1
    || ! User::query()->where('email', 's3-browser@outboss.test')->exists()) {
    throw new RuntimeException('Refusing to drive anything except the isolated S3 fixture.');
}

FakeMailProvider::reset();
if ($operation === 'launch') {
    app(LaunchObjectiveExecution::class)->handle(ExecutionPlan::query()->sole(), User::query()->sole());
}
if ($operation === 'send') {
    app(ScheduleDueCampaignSends::class)->handle();
    foreach (SendAttempt::query()->get() as $attempt) {
        app(SendCampaignMessage::class)->handle((int) $attempt->id);
        app(SendCampaignMessage::class)->handle((int) $attempt->id);
        app(RecordObjectiveExposure::class)->handle((int) $attempt->getAttribute('workspace_id'), (int) $attempt->id);
        app(RecordObjectiveExposure::class)->handle((int) $attempt->getAttribute('workspace_id'), (int) $attempt->id);
    }
}
if ($operation === 'reply') {
    $sent = Message::query()->where('direction', 'outbound')->where('status', 'sent')->sole();
    $recipient = CampaignRecipient::query()->whereKey($sent->campaign_recipient_id)->sole();
    $reply = Message::query()->where('remote_message_id', 's3-browser-human-reply')->first();
    if ($reply === null) {
        $reply = Message::factory()->create([
            'workspace_id' => $sent->getAttribute('workspace_id'),
            'thread_id' => $sent->thread_id,
            'mailbox_id' => $sent->mailbox_id,
            'campaign_recipient_id' => $recipient->id,
            'remote_message_id' => 's3-browser-human-reply',
            'direction' => 'inbound',
            'classification' => 'unknown',
            'status' => 'received',
            'from_email' => $recipient->email_address,
            'subject' => 'Re: '.$sent->subject,
            'body_text' => 'Yes, I would like to discuss the workshop.',
            'received_at' => now(),
        ]);
    }
    app(ApplyInboundCampaignEffects::class)->handle($reply);
    app(ProjectGtmConversation::class)->handle((int) $reply->getAttribute('workspace_id'), (int) $reply->thread_id, (int) $reply->id);
}
if ($operation === 'conversion') {
    $plan = ExecutionPlan::query()->sole();
    $owner = User::query()->sole();
    $action = $plan->actions()->sole();
    $intake = app(IngestConversionEvent::class)->handle(
        $owner->currentWorkspace(),
        (string) $plan->conversion_definition_version_id,
        ['external_event_id' => 'browser-conv-1', 'occurred_at' => now()->toAtomString(), 'account_id' => $action->account_id, 'properties' => []],
        'manual',
        null,
        $owner,
        'browser-conv-1',
    );
    if ($intake->event === null) {
        throw new RuntimeException('The deterministic conversion was not accepted.');
    }
    app(AssessObjectiveAttribution::class)->handle((int) $plan->workspace_id, (string) $intake->event->id);
    app(RebuildObjectiveProgress::class)->handle((int) $plan->workspace_id, (string) $plan->objective_version_id);
}

echo json_encode([
    'operation' => $operation,
    'test_now' => now()->toAtomString(),
    'campaigns' => Campaign::query()->count(),
    'approval_grants' => ApprovalGrant::query()->count(),
    'messages' => Message::query()->count(),
    'attempts_by_state' => SendAttempt::query()->get()->countBy(fn (SendAttempt $attempt): string => (string) $attempt->getRawOriginal('status'))->all(),
    'exposures' => Exposure::query()->count(),
    'conversations' => GtmConversation::query()->count(),
    'recipient_stop_reasons' => CampaignRecipient::query()->pluck('stop_reason')->all(),
    'conversion_events' => ConversionEvent::query()->count(),
    'attribution_states' => AttributionAssessment::query()->pluck('state')->all(),
    'attributed_count' => ObjectiveOutcomeProgress::query()->sum('attributed_count'),
    'fake_sends_this_invocation' => count(FakeMailProvider::$store['sends'] ?? []),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
