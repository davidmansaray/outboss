<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountIntelligenceController;
use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\CampaignEnrollmentController;
use App\Http\Controllers\Api\V1\CampaignRecipientController;
use App\Http\Controllers\Api\V1\ConversionConnectionController;
use App\Http\Controllers\Api\V1\ConversionController;
use App\Http\Controllers\Api\V1\DevicePushController;
use App\Http\Controllers\Api\V1\ExecutionPlanApprovalController;
use App\Http\Controllers\Api\V1\ExecutionPlanCompilationController;
use App\Http\Controllers\Api\V1\ExecutionPlanLaunchController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\InboxCountsController;
use App\Http\Controllers\Api\V1\MailboxController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\ObjectiveController;
use App\Http\Controllers\Api\V1\OfferIntelligenceController;
use App\Http\Controllers\Api\V1\PersonController;
use App\Http\Controllers\Api\V1\ProspectSourceController;
use App\Http\Controllers\Api\V1\SegmentController;
use App\Http\Controllers\Api\V1\SequenceStepController;
use App\Http\Controllers\Api\V1\ServerController;
use App\Http\Controllers\Api\V1\SuppressionController;
use App\Http\Controllers\Api\V1\ThreadController;
use Illuminate\Support\Facades\Route;

// Public discovery for the iOS app: is this address an Outboss server?
Route::prefix('v1')->middleware(['throttle:ingress', 'throttle:api'])->group(function (): void {
    Route::get('server', ServerController::class)->name('api.v1.server');
});

// The iOS app trades a one-time sign-in code (or a refresh token) for a
// workspace-bound access token. It runs before authentication by design.
Route::prefix('v1')->middleware(['throttle:ingress', 'throttle:auth-token'])->group(function (): void {
    Route::post('auth/token', [AuthTokenController::class, 'store'])->name('api.v1.auth.token');
});

Route::prefix('v1')->middleware(['throttle:ingress', 'auth:sanctum', 'throttle:api'])->group(function (): void {
    // Available to any token: who am I and which workspaces can I switch to.
    Route::get('me', MeController::class)->name('api.v1.me');

    // Signed-in devices only; personal API keys get device_session_required.
    Route::post('auth/logout', [AuthTokenController::class, 'destroy'])->name('api.v1.auth.logout');
    Route::put('devices/current/push', [DevicePushController::class, 'update'])->name('api.v1.devices.push.update');
    Route::delete('devices/current/push', [DevicePushController::class, 'destroy'])->name('api.v1.devices.push.destroy');

    Route::middleware('scope:objectives:read')->group(function (): void {
        Route::get('objectives', [ObjectiveController::class, 'index'])->name('api.v1.objectives.index');
        Route::get('objectives/{objective}', [ObjectiveController::class, 'show'])->whereUuid('objective')->name('api.v1.objectives.show');
        Route::get('objectives/{objective}/readiness', [ObjectiveController::class, 'readiness'])->whereUuid('objective')->name('api.v1.objectives.readiness');
        Route::get('gtm-experiments/{experiment}', [ObjectiveController::class, 'showExperiment'])->whereUuid('experiment')->name('api.v1.gtm-experiments.show');
        Route::get('strategies/{strategy}', [ObjectiveController::class, 'showStrategy'])->whereUuid('strategy')->name('api.v1.strategies.show');
        Route::get('objective-runs/{run}', [ObjectiveController::class, 'showRun'])->whereUuid('run')->name('api.v1.objective-runs.show');
        Route::get('execution-plans/{plan}', [ObjectiveController::class, 'showPlan'])->whereUuid('plan')->name('api.v1.execution-plans.show');
        Route::get('segments', [SegmentController::class, 'index'])->name('api.v1.segments.index');
        Route::get('segments/{segment}', [SegmentController::class, 'show'])->whereUuid('segment')->name('api.v1.segments.show');
        Route::post('segments/{segment}/preview', [SegmentController::class, 'preview'])
            ->whereUuid('segment')->middleware('throttle:gtm-mutations')->name('api.v1.segments.preview');
        Route::get('audience-snapshots/{snapshot}', [SegmentController::class, 'showSnapshot'])
            ->whereUuid('snapshot')->name('api.v1.audience-snapshots.show');
        Route::get('audience-snapshots/{snapshot}/members', [SegmentController::class, 'snapshotMembers'])
            ->whereUuid('snapshot')->name('api.v1.audience-snapshots.members');
    });

    Route::middleware('scope:objectives:write')->group(function (): void {
        Route::post('objectives', [ObjectiveController::class, 'store'])
            ->middleware(['throttle:gtm-mutations', 'idempotency'])->name('api.v1.objectives.store');
        Route::post('objectives/{objective}/versions', [ObjectiveController::class, 'version'])
            ->whereUuid('objective')->middleware(['throttle:gtm-mutations', 'idempotency'])->name('api.v1.objectives.versions.store');
        Route::patch('objectives/{objective}/status', [ObjectiveController::class, 'transition'])
            ->whereUuid('objective')->middleware('throttle:gtm-mutations')->name('api.v1.objectives.status.update');
        Route::post('strategies', [ObjectiveController::class, 'storeStrategy'])
            ->middleware(['throttle:gtm-mutations', 'idempotency'])->name('api.v1.strategies.store');
        Route::post('strategies/{strategy}/versions', [ObjectiveController::class, 'versionStrategy'])
            ->whereUuid('strategy')->middleware(['throttle:gtm-mutations', 'idempotency'])->name('api.v1.strategies.versions.store');
        Route::post('objectives/{objective}/experiments', [ObjectiveController::class, 'storeExperiment'])
            ->whereUuid('objective')->middleware(['throttle:gtm-mutations', 'idempotency'])->name('api.v1.objectives.experiments.store');
        Route::post('gtm-experiments/{experiment}/assignments', [ObjectiveController::class, 'freezeAssignments'])
            ->whereUuid('experiment')->middleware(['throttle:gtm-mutations', 'idempotency'])->name('api.v1.gtm-experiments.assignments.store');
        Route::post('objectives/{objective}/runs/preflight', [ObjectiveController::class, 'preflightRun'])
            ->whereUuid('objective')->middleware('throttle:gtm-mutations')->name('api.v1.objective-runs.preflight');
        Route::post('objectives/{objective}/runs', [ObjectiveController::class, 'startRun'])
            ->whereUuid('objective')->middleware(['throttle:gtm-mutations', 'idempotency'])->name('api.v1.objective-runs.store');
        Route::post('objective-runs/{run}/cancel', [ObjectiveController::class, 'cancelRun'])
            ->whereUuid('run')->middleware(['throttle:gtm-mutations', 'idempotency'])->name('api.v1.objective-runs.cancel');
        Route::post('objective-runs/{run}/supersede', [ObjectiveController::class, 'supersedeRun'])
            ->whereUuid('run')->middleware(['throttle:gtm-mutations', 'idempotency'])->name('api.v1.objective-runs.supersede');
        Route::post('segments', [SegmentController::class, 'store'])
            ->middleware(['throttle:gtm-mutations', 'idempotency'])->name('api.v1.segments.store');
        Route::post('segments/{segment}/versions', [SegmentController::class, 'version'])
            ->whereUuid('segment')->middleware(['throttle:gtm-mutations', 'idempotency'])->name('api.v1.segments.versions.store');
        Route::post('segments/{segment}/snapshots', [SegmentController::class, 'snapshot'])
            ->whereUuid('segment')->middleware(['throttle:gtm-mutations', 'idempotency'])->name('api.v1.segments.snapshots.store');
    });

    Route::middleware('scope:objectives:approve')->group(function (): void {
        Route::post('execution-plans/{plan}/approve', [ExecutionPlanApprovalController::class, 'approve'])
            ->whereUuid('plan')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.execution-plans.approve');
        Route::post('execution-plans/{plan}/reject', [ExecutionPlanApprovalController::class, 'reject'])
            ->whereUuid('plan')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.execution-plans.reject');
        Route::post('approval-batches/{batch}/revoke', [ExecutionPlanApprovalController::class, 'revoke'])
            ->whereUuid('batch')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.approval-batches.revoke');
        Route::post('execution-plans/{plan}/compile', ExecutionPlanCompilationController::class)
            ->whereUuid('plan')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.execution-plans.compile');
        Route::post('execution-plans/{plan}/launch', ExecutionPlanLaunchController::class)
            ->whereUuid('plan')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.execution-plans.launch');
    });

    Route::middleware('scope:campaigns:read')->group(function (): void {
        Route::get('campaigns', [CampaignController::class, 'index'])->name('api.v1.campaigns.index');
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('api.v1.campaigns.show');
        Route::get('campaigns/{campaign}/launch-readiness', [CampaignController::class, 'launchReadiness'])->name('api.v1.campaigns.launch-readiness');
        Route::get('campaigns/{campaign}/enrollable-people', [CampaignEnrollmentController::class, 'index'])->name('api.v1.campaigns.enrollable-people');
        Route::get('campaigns/{campaign}/recipients', [CampaignRecipientController::class, 'index'])->name('api.v1.campaigns.recipients.index');
        Route::get('sequence-steps/{step}/preview', [SequenceStepController::class, 'preview'])->name('api.v1.sequence-steps.preview');
    });

    Route::middleware('scope:campaigns:write')->group(function (): void {
        Route::post('campaigns', [CampaignController::class, 'store'])->name('api.v1.campaigns.store');
        Route::patch('campaigns/{campaign}', [CampaignController::class, 'update'])->name('api.v1.campaigns.update');
        Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('api.v1.campaigns.pause');
        Route::post('campaigns/{campaign}/complete', [CampaignController::class, 'complete'])->name('api.v1.campaigns.complete');
        Route::post('campaigns/{campaign}/steps', [SequenceStepController::class, 'store'])->name('api.v1.campaigns.steps.store');
        Route::put('campaigns/{campaign}/steps/order', [SequenceStepController::class, 'reorder'])->name('api.v1.campaigns.steps.reorder');
        Route::patch('sequence-steps/{step}', [SequenceStepController::class, 'update'])->name('api.v1.sequence-steps.update');
        Route::delete('sequence-steps/{step}', [SequenceStepController::class, 'destroy'])->name('api.v1.sequence-steps.destroy');
        Route::post('campaigns/{campaign}/enrollments', [CampaignEnrollmentController::class, 'store'])
            ->middleware('idempotency')
            ->name('api.v1.campaigns.enrollments.store');
        Route::patch('campaign-recipients/{recipient}', [CampaignRecipientController::class, 'update'])->name('api.v1.campaign-recipients.update');
        Route::delete('campaign-recipients/{recipient}', [CampaignRecipientController::class, 'destroy'])->name('api.v1.campaign-recipients.destroy');
    });

    Route::middleware('scope:campaigns:launch')->group(function (): void {
        Route::post('campaigns/{campaign}/launch', [CampaignController::class, 'launch'])
            ->middleware('idempotency')
            ->name('api.v1.campaigns.launch');
        Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume'])->name('api.v1.campaigns.resume');
    });

    Route::middleware('scope:people:read')->group(function (): void {
        Route::get('people', [PersonController::class, 'index'])->name('api.v1.people.index');
        Route::get('people/{person}', [PersonController::class, 'show'])->name('api.v1.people.show');
        Route::get('accounts', [AccountController::class, 'index'])->name('api.v1.accounts.index');
        Route::get('accounts/{account}', [AccountController::class, 'show'])->name('api.v1.accounts.show');
    });

    Route::middleware('scope:people:write')->group(function (): void {
        Route::post('people', [PersonController::class, 'store'])->name('api.v1.people.store');
        Route::patch('people/{person}', [PersonController::class, 'update'])->name('api.v1.people.update');
        Route::delete('people/{person}', [PersonController::class, 'destroy'])->name('api.v1.people.destroy');
        Route::post('accounts', [AccountController::class, 'store'])->name('api.v1.accounts.store');
        Route::patch('accounts/{account}', [AccountController::class, 'update'])->name('api.v1.accounts.update');
        Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->name('api.v1.accounts.destroy');
    });

    // S2 Account intelligence is deliberately a separate, least-privilege
    // surface. All child IDs are resolved through the current token workspace
    // in the controllers; no implicit global lookup is used here.
    Route::middleware('scope:intelligence:read')->group(function (): void {
        Route::get('accounts/{account}/intelligence', [AccountIntelligenceController::class, 'show'])
            ->whereNumber('account')->name('api.v1.accounts.intelligence.show');
        Route::post('accounts/{account}/research/preflight', [AccountIntelligenceController::class, 'preflight'])
            ->whereNumber('account')->name('api.v1.accounts.research.preflight');
        Route::get('account-research-runs', [AccountIntelligenceController::class, 'runs'])
            ->name('api.v1.account-research-runs.index');
        Route::get('account-research-runs/{run}', [AccountIntelligenceController::class, 'run'])
            ->whereUuid('run')->name('api.v1.account-research-runs.show');
        Route::get('research-claims', [AccountIntelligenceController::class, 'claims'])
            ->name('api.v1.research-claims.index');
        Route::get('research-claims/{claim}', [AccountIntelligenceController::class, 'claim'])
            ->whereUuid('claim')->name('api.v1.research-claims.show');
        Route::get('qualification-decisions', [AccountIntelligenceController::class, 'decisions'])
            ->name('api.v1.qualification-decisions.index');
        Route::get('qualification-decisions/{decision}', [AccountIntelligenceController::class, 'decision'])
            ->whereUuid('decision')->name('api.v1.qualification-decisions.show');
    });

    Route::middleware('scope:intelligence:write')->group(function (): void {
        Route::post('accounts/{account}/research-runs', [AccountIntelligenceController::class, 'start'])
            ->whereNumber('account')->middleware(['throttle:gtm-research', 'idempotency'])
            ->name('api.v1.accounts.research-runs.store');
        Route::post('account-research-runs/{run}/cancel', [AccountIntelligenceController::class, 'cancel'])
            ->whereUuid('run')->middleware(['throttle:gtm-research', 'idempotency'])
            ->name('api.v1.account-research-runs.cancel');
        Route::post('research-claims/{claim}/corrections', [AccountIntelligenceController::class, 'correctClaim'])
            ->whereUuid('claim')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.research-claims.corrections.store');
        Route::post('qualification-decisions/{decision}/reviews', [AccountIntelligenceController::class, 'reviewDecision'])
            ->whereUuid('decision')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.qualification-decisions.reviews.store');
    });

    Route::middleware('scope:conversions:read')->group(function (): void {
        Route::get('conversion-definitions', [ConversionController::class, 'definitions'])
            ->name('api.v1.conversion-definitions.index');
        Route::get('conversion-definitions/{definition}', [ConversionController::class, 'definition'])
            ->whereUuid('definition')->name('api.v1.conversion-definitions.show');
        Route::get('conversion-events', [ConversionController::class, 'events'])
            ->name('api.v1.conversion-events.index');
        Route::get('conversion-events/{event}', [ConversionController::class, 'event'])
            ->whereUuid('event')->name('api.v1.conversion-events.show');
    });

    Route::middleware('scope:conversions:write')->group(function (): void {
        Route::post('conversion-definitions', [ConversionController::class, 'storeDefinition'])
            ->middleware(['throttle:gtm-mutations', 'idempotency'])->name('api.v1.conversion-definitions.store');
        Route::post('conversion-definitions/{definition}/versions', [ConversionController::class, 'versionDefinition'])
            ->whereUuid('definition')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.conversion-definitions.versions.store');
        Route::post('conversion-definitions/{definition}/activate', [ConversionController::class, 'activateDefinition'])
            ->whereUuid('definition')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.conversion-definitions.activate');
        Route::post('conversion-definitions/{definition}/archive', [ConversionController::class, 'archiveDefinition'])
            ->whereUuid('definition')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.conversion-definitions.archive');
        Route::post('conversion-events', [ConversionController::class, 'storeEvent'])
            ->middleware(['throttle:gtm-mutations', 'idempotency'])->name('api.v1.conversion-events.store');
        Route::post('conversion-events/{event}/reviews', [ConversionController::class, 'reviewEvent'])
            ->whereUuid('event')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.conversion-events.reviews.store');
        Route::post('conversion-events/{event}/corrections', [ConversionController::class, 'correctEvent'])
            ->whereUuid('event')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.conversion-events.corrections.store');
        Route::post('conversion-events/{event}/reversals', [ConversionController::class, 'reverseEvent'])
            ->whereUuid('event')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.conversion-events.reversals.store');
    });

    Route::middleware('scope:conversion-connections:write')->group(function (): void {
        Route::post('conversion-connections', [ConversionConnectionController::class, 'store'])
            ->middleware('throttle:gtm-mutations')
            ->name('api.v1.conversion-connections.store');
        Route::post('conversion-connections/{connection}/rotate', [ConversionConnectionController::class, 'rotate'])
            ->whereUuid('connection')->middleware('throttle:gtm-mutations')
            ->name('api.v1.conversion-connections.rotate');
        Route::post('conversion-connections/{connection}/disable', [ConversionConnectionController::class, 'disable'])
            ->whereUuid('connection')->middleware('throttle:gtm-mutations')
            ->name('api.v1.conversion-connections.disable');
    });

    Route::middleware('scope:offers:read')->group(function (): void {
        Route::get('offers', [OfferIntelligenceController::class, 'index'])
            ->name('api.v1.offers.index');
        Route::get('offers/{offer}', [OfferIntelligenceController::class, 'show'])
            ->whereUuid('offer')->name('api.v1.offers.show');
        Route::get('offers/{offer}/readiness', [OfferIntelligenceController::class, 'readiness'])
            ->whereUuid('offer')->name('api.v1.offers.readiness');
        Route::get('fulfilment-requests', [OfferIntelligenceController::class, 'fulfilmentRequests'])
            ->name('api.v1.fulfilment-requests.index');
        Route::get('assets', [OfferIntelligenceController::class, 'assets'])
            ->name('api.v1.assets.index');
        Route::get('assets/{asset}', [OfferIntelligenceController::class, 'showAsset'])
            ->whereUuid('asset')->name('api.v1.assets.show');
    });

    Route::middleware('scope:offers:write')->group(function (): void {
        Route::post('offers', [OfferIntelligenceController::class, 'store'])
            ->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.offers.store');
        Route::patch('offers/{offer}/configure', [OfferIntelligenceController::class, 'configure'])
            ->whereUuid('offer')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.offers.configure');
        Route::post('assets', [OfferIntelligenceController::class, 'storeAsset'])
            ->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.assets.store');
        Route::post('assets/{asset}/versions', [OfferIntelligenceController::class, 'versionAsset'])
            ->whereUuid('asset')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.assets.versions.store');
        Route::post('assets/{asset}/archive', [OfferIntelligenceController::class, 'archiveAsset'])
            ->whereUuid('asset')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.assets.archive');
        Route::post('fulfilment-capabilities', [OfferIntelligenceController::class, 'storeCapability'])
            ->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.fulfilment-capabilities.store');
    });

    Route::middleware('scope:offers:approve')->group(function (): void {
        Route::post('offers/{offer}/reviews', [OfferIntelligenceController::class, 'review'])
            ->whereUuid('offer')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.offers.reviews.store');
        Route::post('offers/{offer}/activate', [OfferIntelligenceController::class, 'activate'])
            ->whereUuid('offer')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('api.v1.offers.activate');
    });

    Route::middleware('scope:sources:read')->group(function (): void {
        Route::get('prospect-sources', [ProspectSourceController::class, 'index'])->name('api.v1.prospect-sources.index');
        Route::get('prospect-sources/{source}', [ProspectSourceController::class, 'show'])
            ->whereUuid('source')->name('api.v1.prospect-sources.show');
        Route::get('prospect-sources/{source}/runs', [ProspectSourceController::class, 'runs'])
            ->whereUuid('source')->name('api.v1.prospect-sources.runs.index');
        Route::get('source-runs/{run}', [ProspectSourceController::class, 'showRun'])
            ->whereUuid('run')->name('api.v1.source-runs.show');
        Route::get('source-runs/{run}/observations', [ProspectSourceController::class, 'observations'])
            ->whereUuid('run')->name('api.v1.source-runs.observations.index');
    });

    Route::middleware('scope:sources:write')->group(function (): void {
        Route::post('prospect-sources', [ProspectSourceController::class, 'store'])
            ->middleware('idempotency')->name('api.v1.prospect-sources.store');
        Route::patch('prospect-sources/{source}', [ProspectSourceController::class, 'update'])
            ->whereUuid('source')->name('api.v1.prospect-sources.update');
        Route::post('prospect-sources/{source}/runs', [ProspectSourceController::class, 'startRun'])
            ->whereUuid('source')->middleware('idempotency')->name('api.v1.prospect-sources.runs.store');
        Route::post('source-runs/{run}/commit', [ProspectSourceController::class, 'commitRun'])
            ->whereUuid('run')->middleware('idempotency')->name('api.v1.source-runs.commit');
        Route::post('prospect-imports', [ProspectSourceController::class, 'batch'])
            ->middleware('idempotency')->name('api.v1.prospect-imports.store');
    });

    Route::middleware('scope:inbox:read')->group(function (): void {
        Route::get('threads', [ThreadController::class, 'index'])->name('api.v1.threads.index');
        Route::get('threads/changes', [ThreadController::class, 'changes'])->name('api.v1.threads.changes');
        Route::get('threads/{thread}', [ThreadController::class, 'show'])->whereNumber('thread')->name('api.v1.threads.show');
        Route::get('inbox/counts', InboxCountsController::class)->name('api.v1.inbox.counts');
    });

    Route::middleware('scope:inbox:send')->group(function (): void {
        Route::post('threads/{thread}/reply', [ThreadController::class, 'reply'])
            ->middleware('idempotency')
            ->name('api.v1.threads.reply');
        Route::post('threads/{thread}/done', [ThreadController::class, 'done'])->name('api.v1.threads.done');
        Route::post('threads/{thread}/read', [ThreadController::class, 'read'])->name('api.v1.threads.read');
        Route::post('threads/{thread}/unread', [ThreadController::class, 'unread'])->name('api.v1.threads.unread');
        Route::post('threads/{thread}/classification', [ThreadController::class, 'classify'])->name('api.v1.threads.classify');
        Route::post('messages', [MessageController::class, 'store'])
            ->middleware('idempotency')
            ->name('api.v1.messages.store');
        Route::post('sequence-steps/{step}/test', [SequenceStepController::class, 'test'])
            ->middleware('idempotency')
            ->name('api.v1.sequence-steps.test');
    });

    Route::middleware('scope:mailboxes:read')->group(function (): void {
        Route::get('mailboxes', [MailboxController::class, 'index'])->name('api.v1.mailboxes.index');
        Route::get('mailboxes/{mailbox}', [MailboxController::class, 'show'])->name('api.v1.mailboxes.show');
        Route::get('mailboxes/{mailbox}/auth-check', [MailboxController::class, 'authCheck'])->name('api.v1.mailboxes.auth-check');
    });

    Route::middleware('scope:mailboxes:write')->group(function (): void {
        Route::post('mailboxes/{mailbox}/sync', [MailboxController::class, 'sync'])->name('api.v1.mailboxes.sync');
        Route::patch('mailboxes/{mailbox}/sending', [MailboxController::class, 'updateSending'])->name('api.v1.mailboxes.sending');
        Route::post('mailboxes/{mailbox}/resume-sending', [MailboxController::class, 'resumeSending'])->name('api.v1.mailboxes.resume-sending');
    });

    Route::middleware('scope:suppressions:read')->group(function (): void {
        Route::get('suppressions', [SuppressionController::class, 'index'])->name('api.v1.suppressions.index');
    });

    Route::middleware('scope:suppressions:write')->group(function (): void {
        Route::post('suppressions', [SuppressionController::class, 'store'])->name('api.v1.suppressions.store');
        Route::delete('suppressions/{entry}', [SuppressionController::class, 'destroy'])->name('api.v1.suppressions.destroy');
    });

    Route::middleware('scope:exports:read')->group(function (): void {
        Route::get('exports/enrollments', [ExportController::class, 'enrollments'])->name('api.v1.exports.enrollments');
        Route::get('exports/suppressions', [ExportController::class, 'suppressions'])->name('api.v1.exports.suppressions');
        Route::get('exports/campaign-outcomes', [ExportController::class, 'campaignOutcomes'])->name('api.v1.exports.campaign-outcomes');
        Route::get('exports/s2/{section}', [ExportController::class, 's2'])
            ->where('section', '[a-z_]+')->name('api.v1.exports.s2');
    });
});
