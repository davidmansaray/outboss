<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountIntelligenceController;
use App\Http\Controllers\Api\V1\ConversionConnectionController as ApiConversionConnectionController;
use App\Http\Controllers\Api\V1\ConversionController as ApiConversionController;
use App\Http\Controllers\Api\V1\ConversionWebhookController;
use App\Http\Controllers\Api\V1\ObjectiveController as ApiObjectiveController;
use App\Http\Controllers\Api\V1\OfferIntelligenceController as ApiOfferIntelligenceController;
use App\Http\Controllers\Api\V1\SegmentController as ApiSegmentController;
use App\Http\Controllers\ApprovalBatchController;
use App\Http\Controllers\CampaignCompositionController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignEnrollmentController;
use App\Http\Controllers\CampaignRecipientController;
use App\Http\Controllers\ExecutionPlanApprovalController;
use App\Http\Controllers\ExecutionPlanCompilationController;
use App\Http\Controllers\ExecutionPlanController;
use App\Http\Controllers\ExecutionPlanLaunchController;
use App\Http\Controllers\HumanCampaignReviewController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\Mobile\MobileAuthorizeController;
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\ObjectiveController;
use App\Http\Controllers\ObjectiveRunController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OfferSetupController;
use App\Http\Controllers\PeopleAccountsController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\PersonImportController;
use App\Http\Controllers\ProspectSourceController;
use App\Http\Controllers\S2BrowserProofController;
use App\Http\Controllers\SequenceStepController;
use App\Http\Controllers\SuppressionController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\UniboxController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceExportController;
use App\Http\Middleware\RememberMobileSignInRedirect;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check()
    ? redirect()->route('unibox.index')
    : redirect()->route('login'))->name('home');

Route::get('/t/{trackingToken}.gif', [TrackingController::class, 'show'])
    ->where('trackingToken', '[0-9a-fA-F-]{36}')
    ->name('tracking.pixel');

Route::get('/unsub/{token}', [UnsubscribeController::class, 'show'])
    ->where('token', '[0-9a-fA-F-]{36}')
    ->name('unsubscribe.show');
Route::post('/unsub/{token}', [UnsubscribeController::class, 'store'])
    ->where('token', '[0-9a-fA-F-]{36}')
    ->name('unsubscribe.store');

Route::post('/webhooks/v1/conversions/{publicConnectionId}', [ConversionWebhookController::class, 'store'])
    ->whereUuid('publicConnectionId')
    ->middleware('throttle:ingress')
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('webhooks.v1.conversions.store');

// iOS sign-in: pick a workspace. Fortify signs the person in first and
// returns here. The guest redirect to login is recorded before `auth` runs, so
// a fresh login for this attempt can skip the page (one workspace only).
Route::get('/mobile/authorize', [MobileAuthorizeController::class, 'show'])
    ->middleware([RememberMobileSignInRedirect::class, 'auth', 'verified'])
    ->name('mobile.authorize');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', fn () => redirect()->route('unibox.index'))->name('dashboard');

    Route::post('/mobile/authorize', [MobileAuthorizeController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('mobile.authorize.store');

    if (app()->environment('testing') && config('intelligence.testing.fake_providers')) {
        Route::get('/_test/s2-proof', S2BrowserProofController::class)->name('testing.s2-proof');
    }

    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::post('/workspaces/{workspaceId}/switch', [WorkspaceController::class, 'switch'])
        ->whereNumber('workspaceId')
        ->name('workspaces.switch');

    Route::get('/execution-plans/{plan}', [ExecutionPlanController::class, 'show'])
        ->whereUuid('plan')
        ->name('execution-plans.show');
    Route::post('/execution-plans/{plan}/approve', [ExecutionPlanApprovalController::class, 'approve'])
        ->whereUuid('plan')->middleware(['throttle:gtm-mutations', 'idempotency'])
        ->name('execution-plans.approve');
    Route::post('/execution-plans/{plan}/reject', [ExecutionPlanApprovalController::class, 'reject'])
        ->whereUuid('plan')->middleware(['throttle:gtm-mutations', 'idempotency'])
        ->name('execution-plans.reject');
    Route::post('/execution-plans/{plan}/compile', ExecutionPlanCompilationController::class)
        ->whereUuid('plan')->middleware(['throttle:gtm-mutations', 'idempotency'])
        ->name('execution-plans.compile');
    Route::post('/execution-plans/{plan}/launch', ExecutionPlanLaunchController::class)
        ->whereUuid('plan')->middleware(['throttle:gtm-mutations', 'idempotency'])
        ->name('execution-plans.launch');
    Route::post('/approval-batches/{batch}/revoke', [ApprovalBatchController::class, 'revoke'])
        ->whereUuid('batch')->middleware(['throttle:gtm-mutations', 'idempotency'])
        ->name('approval-batches.revoke');
    Route::get('/objectives', [ObjectiveController::class, 'index'])
        ->name('objectives.index');
    Route::post('/objectives', [ObjectiveController::class, 'store'])
        ->middleware('throttle:gtm-mutations')->name('objectives.store');
    Route::get('/objectives/create', [ObjectiveController::class, 'create'])->name('objectives.create');
    Route::post('/objectives/{objective}/import-authority', [ObjectiveController::class, 'importAuthority'])
        ->middleware('throttle:gtm-mutations')->name('objectives.import-authority');
    Route::post('/objectives/{objective}/measurement', [ObjectiveController::class, 'measurement'])
        ->whereUuid('objective')->middleware('throttle:gtm-mutations')->name('objectives.measurement');
    Route::get('/objectives/{objective}', [ObjectiveController::class, 'show'])
        ->whereUuid('objective')->name('objectives.show');
    Route::patch('/objectives/{objective}', [ObjectiveController::class, 'update'])
        ->whereUuid('objective')->middleware('throttle:gtm-mutations')->name('objectives.update');
    Route::post('/objectives/{objective}/runs', [ObjectiveRunController::class, 'store'])
        ->whereUuid('objective')->middleware(['throttle:gtm-mutations', 'idempotency'])
        ->name('objectives.runs.store');

    Route::prefix('/objective-inputs')->name('objective-inputs.')->group(function (): void {
        Route::get('/objectives', [ApiObjectiveController::class, 'index'])->name('objectives.index');
        Route::post('/objectives', [ApiObjectiveController::class, 'store'])
            ->middleware('throttle:gtm-mutations')->name('objectives.store');
        Route::get('/objectives/{objective}', [ApiObjectiveController::class, 'show'])
            ->whereUuid('objective')->name('objectives.show');
        Route::post('/objectives/{objective}/versions', [ApiObjectiveController::class, 'version'])
            ->whereUuid('objective')->middleware('throttle:gtm-mutations')->name('objectives.versions.store');
        Route::get('/objectives/{objective}/readiness', [ApiObjectiveController::class, 'readiness'])
            ->whereUuid('objective')->name('objectives.readiness');
        Route::patch('/objectives/{objective}/status', [ApiObjectiveController::class, 'transition'])
            ->whereUuid('objective')->middleware('throttle:gtm-mutations')->name('objectives.status.update');
        Route::post('/strategies', [ApiObjectiveController::class, 'storeStrategy'])
            ->middleware('throttle:gtm-mutations')->name('strategies.store');
        Route::get('/strategies/{strategy}', [ApiObjectiveController::class, 'showStrategy'])
            ->whereUuid('strategy')->name('strategies.show');
        Route::post('/strategies/{strategy}/versions', [ApiObjectiveController::class, 'versionStrategy'])
            ->whereUuid('strategy')->middleware('throttle:gtm-mutations')->name('strategies.versions.store');
        Route::post('/objectives/{objective}/experiments', [ApiObjectiveController::class, 'storeExperiment'])
            ->whereUuid('objective')->middleware('throttle:gtm-mutations')->name('objectives.experiments.store');
        Route::post('/gtm-experiments/{experiment}/assignments', [ApiObjectiveController::class, 'freezeAssignments'])
            ->whereUuid('experiment')->middleware('throttle:gtm-mutations')->name('gtm-experiments.assignments.store');
        Route::get('/gtm-experiments/{experiment}', [ApiObjectiveController::class, 'showExperiment'])
            ->whereUuid('experiment')->name('gtm-experiments.show');
        Route::post('/objectives/{objective}/runs/preflight', [ApiObjectiveController::class, 'preflightRun'])
            ->whereUuid('objective')->middleware('throttle:gtm-mutations')->name('objective-runs.preflight');
        Route::post('/objectives/{objective}/runs', [ApiObjectiveController::class, 'startRun'])
            ->whereUuid('objective')->middleware(['throttle:gtm-mutations', 'idempotency'])->name('objective-runs.store');
        Route::get('/objective-runs/{run}', [ApiObjectiveController::class, 'showRun'])
            ->whereUuid('run')->name('objective-runs.show');
        Route::post('/objective-runs/{run}/cancel', [ApiObjectiveController::class, 'cancelRun'])
            ->whereUuid('run')->middleware('throttle:gtm-mutations')->name('objective-runs.cancel');
        Route::post('/objective-runs/{run}/supersede', [ApiObjectiveController::class, 'supersedeRun'])
            ->whereUuid('run')->middleware(['throttle:gtm-mutations', 'idempotency'])->name('objective-runs.supersede');
        Route::get('/execution-plans/{plan}', [ApiObjectiveController::class, 'showPlan'])
            ->whereUuid('plan')->name('execution-plans.show');
        Route::get('/segments', [ApiSegmentController::class, 'index'])->name('segments.index');
        Route::post('/segments', [ApiSegmentController::class, 'store'])
            ->middleware('throttle:gtm-mutations')->name('segments.store');
        Route::post('/segments/{segment}/versions', [ApiSegmentController::class, 'version'])
            ->whereUuid('segment')->middleware('throttle:gtm-mutations')->name('segments.versions.store');
        Route::post('/segments/{segment}/preview', [ApiSegmentController::class, 'preview'])
            ->whereUuid('segment')->middleware('throttle:gtm-mutations')->name('segments.preview');
        Route::post('/segments/{segment}/snapshots', [ApiSegmentController::class, 'snapshot'])
            ->whereUuid('segment')->middleware('throttle:gtm-mutations')->name('segments.snapshots.store');
        Route::get('/audience-snapshots/{snapshot}', [ApiSegmentController::class, 'showSnapshot'])
            ->whereUuid('snapshot')->name('audience-snapshots.show');
    });

    Route::get('/unibox', [UniboxController::class, 'index'])->name('unibox.index');
    Route::get('/threads/{thread}', [UniboxController::class, 'show'])->name('threads.show');
    Route::post('/threads/{thread}/reply', [UniboxController::class, 'reply'])->name('threads.reply');
    Route::post('/threads/{thread}/done', [UniboxController::class, 'done'])->name('threads.done');
    Route::post('/threads/{thread}/classification', [UniboxController::class, 'classify'])->name('threads.classify');
    Route::post('/messages', [UniboxController::class, 'compose'])->name('messages.store');

    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::get('/campaigns/{campaign}/review', [HumanCampaignReviewController::class, 'show'])->name('campaigns.review');
    Route::post('/campaigns/{campaign}/review', [HumanCampaignReviewController::class, 'prepare'])->middleware('throttle:gtm-mutations')->name('campaigns.review.prepare');
    Route::post('/campaigns/{campaign}/review/{plan}/approve', [HumanCampaignReviewController::class, 'approve'])->middleware('throttle:gtm-mutations')->name('campaigns.review.approve');
    Route::post('/campaigns/{campaign}/review/{plan}/launch', [HumanCampaignReviewController::class, 'launch'])->middleware('throttle:gtm-mutations')->name('campaigns.review.launch');
    Route::post('/campaigns/{campaign}/review/{plan}/discard', [HumanCampaignReviewController::class, 'discard'])->middleware('throttle:gtm-mutations')->name('campaigns.review.discard');
    Route::post('/campaigns/{campaign}/review/{plan}/return-to-draft', [HumanCampaignReviewController::class, 'returnToDraft'])->middleware('throttle:gtm-mutations')->name('campaigns.review.return-to-draft');
    Route::post('/campaigns/{campaign}/composition', [CampaignCompositionController::class, 'save'])->middleware('throttle:gtm-mutations')->name('campaigns.composition');
    Route::patch('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
    Route::post('/campaigns/{campaign}/redistribute-inboxes', [CampaignController::class, 'redistributeInboxes'])->middleware('throttle:gtm-mutations')->name('campaigns.redistribute-inboxes');
    Route::post('/campaigns/{campaign}/launch', [CampaignController::class, 'launch'])->name('campaigns.launch');
    Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('campaigns.pause');
    Route::post('/campaigns/{campaign}/resume', [CampaignController::class, 'resume'])->name('campaigns.resume');
    Route::post('/campaigns/{campaign}/complete', [CampaignController::class, 'complete'])->name('campaigns.complete');
    Route::post('/campaigns/{campaign}/recipient-imports/preview', [CampaignController::class, 'previewImport'])->name('campaigns.imports.preview');
    Route::get('/campaigns/{campaign}/recipient-imports/{import}/rows', [CampaignController::class, 'importRows'])->name('campaigns.imports.rows');
    Route::post('/campaigns/{campaign}/recipient-imports/{import}/commit', [CampaignController::class, 'commitImport'])->name('campaigns.imports.commit');
    Route::get('/campaigns/{campaign}/enrollable-people', [CampaignEnrollmentController::class, 'index'])->name('campaigns.enrollable-people');
    Route::post('/campaigns/{campaign}/enrollments', [CampaignEnrollmentController::class, 'store'])->name('campaigns.enrollments.store');
    Route::post('/campaigns/{campaign}/steps', [SequenceStepController::class, 'store'])->name('campaigns.steps.store');
    Route::put('/campaigns/{campaign}/steps/order', [SequenceStepController::class, 'reorder'])->name('campaigns.steps.reorder');
    Route::get('/sequence-steps/{step}/preview', [SequenceStepController::class, 'preview'])->name('sequence-steps.preview');
    Route::patch('/sequence-steps/{step}', [SequenceStepController::class, 'update'])->name('sequence-steps.update');
    Route::delete('/sequence-steps/{step}', [SequenceStepController::class, 'destroy'])->name('sequence-steps.destroy');
    Route::post('/sequence-steps/{step}/test', [SequenceStepController::class, 'test'])->name('sequence-steps.test');
    Route::patch('/campaign-recipients/{recipient}', [CampaignRecipientController::class, 'update'])->name('campaign-recipients.update');
    Route::delete('/campaign-recipients/{recipient}', [CampaignRecipientController::class, 'destroy'])->name('campaign-recipients.destroy');

    Route::get('/offers', [OfferController::class, 'index'])->name('offers.index');
    Route::get('/offers/{offer}/setup', [OfferSetupController::class, 'show'])->whereUuid('offer')->name('offers.setup');
    Route::patch('/offers/{offer}/setup', [OfferSetupController::class, 'save'])->whereUuid('offer')->middleware('throttle:gtm-mutations')->name('offers.setup.save');
    Route::post('/offers/{offer}/setup/measurement', [OfferSetupController::class, 'measurement'])->whereUuid('offer')->middleware('throttle:gtm-mutations')->name('offers.setup.measurement');
    Route::post('/offers/{offer}/setup/review', [OfferSetupController::class, 'review'])->whereUuid('offer')->middleware('throttle:gtm-mutations')->name('offers.setup.review');
    Route::post('/offers', [OfferController::class, 'store'])
        ->middleware('throttle:gtm-mutations')
        ->name('offers.store');
    Route::patch('/offers/{offer}', [OfferController::class, 'update'])
        ->middleware('throttle:gtm-mutations')
        ->whereUuid('offer')
        ->name('offers.update');
    Route::patch('/offers/{offer}/status', [OfferController::class, 'status'])
        ->middleware('throttle:gtm-mutations')
        ->whereUuid('offer')
        ->name('offers.status.update');
    Route::get('/offers/{offer}/history', [OfferController::class, 'history'])
        ->whereUuid('offer')
        ->name('offers.history');
    Route::delete('/offers/{offer}', [OfferController::class, 'destroy'])
        ->middleware('throttle:gtm-mutations')
        ->whereUuid('offer')
        ->name('offers.destroy');

    Route::get('/prospects', [PeopleAccountsController::class, 'index'])->name('prospects.index');
    Route::get('/prospect-sources', [ProspectSourceController::class, 'index'])->name('prospect-sources.index');
    Route::post('/prospect-sources', [ProspectSourceController::class, 'store'])->name('prospect-sources.store');
    Route::get('/prospect-sources/{source}', [ProspectSourceController::class, 'show'])
        ->whereUuid('source')->name('prospect-sources.show');
    Route::patch('/prospect-sources/{source}', [ProspectSourceController::class, 'update'])
        ->whereUuid('source')->name('prospect-sources.update');
    Route::post('/prospect-sources/{source}/archive', [ProspectSourceController::class, 'archive'])
        ->whereUuid('source')->name('prospect-sources.archive');
    Route::post('/prospect-sources/{source}/runs', [ProspectSourceController::class, 'startRun'])
        ->whereUuid('source')->name('prospect-sources.runs.store');
    Route::get('/prospect-sources/{source}/runs/{run}', [ProspectSourceController::class, 'run'])
        ->whereUuid(['source', 'run'])->name('prospect-sources.runs.show');
    Route::post('/prospect-sources/{source}/runs/{run}/commit', [ProspectSourceController::class, 'commit'])
        ->whereUuid(['source', 'run'])->name('prospect-sources.runs.commit');
    Route::post('/prospect-sources/{source}/runs/{run}/retry', [ProspectSourceController::class, 'retry'])
        ->whereUuid(['source', 'run'])->name('prospect-sources.runs.retry');
    Route::get('/prospect-sources/{source}/runs/{run}/errors.csv', [ProspectSourceController::class, 'errors'])
        ->whereUuid(['source', 'run'])->name('prospect-sources.runs.errors');
    Route::get('/prospect-sources/{source}/export', [ProspectSourceController::class, 'export'])
        ->whereUuid('source')->name('prospect-sources.export');
    Route::delete('/prospect-sources/{source}/data', [ProspectSourceController::class, 'deleteData'])
        ->whereUuid('source')->name('prospect-sources.data.destroy');
    Route::get('/people', fn () => redirect()->route(
        'prospects.index',
        request()->query(),
        301,
    ))->name('people.legacy');
    Route::get('/people/export', [PersonController::class, 'export'])->name('people.export');
    Route::post('/people', [PersonController::class, 'store'])->name('people.store');
    Route::patch('/people/{person}', [PersonController::class, 'update'])->name('people.update');
    Route::delete('/people/{person}', [PersonController::class, 'destroy'])->name('people.destroy');
    Route::post('/people/imports/preview', [PersonImportController::class, 'preview'])->name('people.imports.preview');
    Route::post('/people/imports/{import}/commit', [PersonImportController::class, 'commit'])->name('people.imports.commit');
    Route::get('/accounts', [PeopleAccountsController::class, 'index'])->name('accounts.index');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    // Register export before any future GET /accounts/{account} sibling.
    Route::get('/accounts/export', [AccountController::class, 'export'])->name('accounts.export');
    Route::patch('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

    // S2 keeps the intelligence read model separate from the compact
    // prospects registry. All records are scoped again in the controller so
    // IDs copied from another workspace resolve as a safe 404.
    Route::prefix('/accounts/{account}/intelligence')->whereNumber('account')->name('accounts.intelligence.')->group(function (): void {
        Route::get('/', [AccountIntelligenceController::class, 'show'])->name('show');
        Route::post('/preflight', [AccountIntelligenceController::class, 'preflight'])->name('preflight');
        Route::post('/research-runs', [AccountIntelligenceController::class, 'startResearch'])->name('research-runs.store');
        Route::get('/research-runs/{run}', [AccountIntelligenceController::class, 'run'])
            ->whereUuid('run')->name('research-runs.show');
    });
    Route::post('/account-research-runs/{run}/cancel', [AccountIntelligenceController::class, 'cancelRun'])
        ->whereUuid('run')->name('account-research-runs.cancel');
    Route::post('/account-research-runs/{run}/refresh', [AccountIntelligenceController::class, 'refreshRun'])
        ->whereUuid('run')->name('account-research-runs.refresh');
    Route::post('/research-claims/{claim}/corrections', [AccountIntelligenceController::class, 'correctClaim'])
        ->whereUuid('claim')->name('research-claims.corrections');
    Route::post('/qualification-decisions/{decision}/reviews', [AccountIntelligenceController::class, 'reviewQualification'])
        ->whereUuid('decision')->name('qualification-decisions.reviews');

    // These two endpoints are intentionally narrow adapters around the
    // existing Offer readiness/review services. They are useful when an
    // intelligence page is opened with a linked operational Offer.
    Route::post('/offers/{offer}/readiness', [AccountIntelligenceController::class, 'evaluateOffer'])
        ->whereUuid('offer')->name('offers.readiness');
    Route::post('/offers/{offer}/review', [AccountIntelligenceController::class, 'reviewOffer'])
        ->whereUuid('offer')->name('offers.review');

    // Session-authenticated JSON edges let the focused S2 browser workflow
    // configure its explicit contracts without granting campaign or sending
    // authority. They call the same transport/domain actions as REST v1.
    Route::prefix('/s2')->name('s2.')->group(function (): void {
        Route::post('/conversion-definitions', [ApiConversionController::class, 'storeDefinition'])
            ->middleware(['throttle:gtm-mutations', 'idempotency'])->name('conversion-definitions.store');
        Route::post('/conversion-definitions/{definition}/activate', [ApiConversionController::class, 'activateDefinition'])
            ->whereUuid('definition')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('conversion-definitions.activate');
        Route::post('/conversion-events', [ApiConversionController::class, 'storeEvent'])
            ->middleware(['throttle:gtm-mutations', 'idempotency'])->name('conversion-events.store');
        Route::get('/conversion-events', [ApiConversionController::class, 'events'])
            ->name('conversion-events.index');
        Route::get('/conversion-events/{event}', [ApiConversionController::class, 'event'])
            ->whereUuid('event')->name('conversion-events.show');
        Route::post('/conversion-events/{event}/reviews', [ApiConversionController::class, 'reviewEvent'])
            ->whereUuid('event')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('conversion-events.reviews.store');
        Route::post('/conversion-events/{event}/corrections', [ApiConversionController::class, 'correctEvent'])
            ->whereUuid('event')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('conversion-events.corrections.store');
        Route::post('/conversion-events/{event}/reversals', [ApiConversionController::class, 'reverseEvent'])
            ->whereUuid('event')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('conversion-events.reversals.store');

        Route::post('/conversion-connections', [ApiConversionConnectionController::class, 'store'])
            ->middleware('throttle:gtm-mutations')
            ->name('conversion-connections.store');
        Route::post('/conversion-connections/{connection}/rotate', [ApiConversionConnectionController::class, 'rotate'])
            ->whereUuid('connection')->middleware('throttle:gtm-mutations')
            ->name('conversion-connections.rotate');
        Route::post('/conversion-connections/{connection}/disable', [ApiConversionConnectionController::class, 'disable'])
            ->whereUuid('connection')->middleware('throttle:gtm-mutations')
            ->name('conversion-connections.disable');

        Route::get('/offers/{offer}', [ApiOfferIntelligenceController::class, 'show'])
            ->whereUuid('offer')->name('offers.show');
        Route::get('/offers/{offer}/readiness', [ApiOfferIntelligenceController::class, 'readiness'])
            ->whereUuid('offer')->name('offers.readiness');
        Route::get('/fulfilment-requests', [ApiOfferIntelligenceController::class, 'fulfilmentRequests'])
            ->name('fulfilment-requests.index');
        Route::get('/assets', [ApiOfferIntelligenceController::class, 'assets'])
            ->name('assets.index');
        Route::get('/assets/{asset}', [ApiOfferIntelligenceController::class, 'showAsset'])
            ->whereUuid('asset')->name('assets.show');
        Route::post('/offers', [ApiOfferIntelligenceController::class, 'store'])
            ->middleware(['throttle:gtm-mutations', 'idempotency'])->name('offers.store');
        Route::patch('/offers/{offer}/configure', [ApiOfferIntelligenceController::class, 'configure'])
            ->whereUuid('offer')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('offers.configure');
        Route::post('/offers/{offer}/activate', [ApiOfferIntelligenceController::class, 'activate'])
            ->whereUuid('offer')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('offers.activate');
        Route::post('/assets', [ApiOfferIntelligenceController::class, 'storeAsset'])
            ->middleware(['throttle:gtm-mutations', 'idempotency'])->name('assets.store');
        Route::post('/assets/{asset}/versions', [ApiOfferIntelligenceController::class, 'versionAsset'])
            ->whereUuid('asset')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('assets.versions.store');
        Route::post('/assets/{asset}/archive', [ApiOfferIntelligenceController::class, 'archiveAsset'])
            ->whereUuid('asset')->middleware(['throttle:gtm-mutations', 'idempotency'])
            ->name('assets.archive');
        Route::post('/fulfilment-capabilities', [ApiOfferIntelligenceController::class, 'storeCapability'])
            ->middleware(['throttle:gtm-mutations', 'idempotency'])->name('fulfilment-capabilities.store');
    });
    Route::get('/settings/suppressions', [SuppressionController::class, 'index'])->name('suppressions.index');
    Route::delete('/settings/suppressions/{entry}', [SuppressionController::class, 'destroy'])->name('suppressions.destroy');
    Route::get('/settings/exports', [WorkspaceExportController::class, 'index'])->name('data-export.index');
    Route::redirect('/settings/data', '/settings/exports', 301)->name('data-export.legacy');
    Route::get('/exports/enrollments', [WorkspaceExportController::class, 'enrollments'])->name('exports.enrollments');
    Route::get('/exports/suppressions', [WorkspaceExportController::class, 'suppressions'])->name('exports.suppressions');
    Route::get('/exports/campaign-outcomes', [WorkspaceExportController::class, 'campaignOutcomes'])->name('exports.campaign-outcomes');
    Route::get('/exports/sources', [WorkspaceExportController::class, 'sources'])->name('exports.sources');
    Route::get('/exports/integrations', [WorkspaceExportController::class, 'integrations'])->name('exports.integrations');
    Route::get('/exports/s2/{section}', [WorkspaceExportController::class, 's2'])
        ->where('section', '[a-z_]+')->name('exports.s2');

    Route::get('/settings/mailboxes', [MailboxController::class, 'index'])->name('mailboxes.index');
    Route::post('/mailboxes/{mailbox}/reconnect', [OAuthController::class, 'redirect'])->name('mailboxes.reconnect');
    Route::post('/mailboxes/{mailbox}/sync', [MailboxController::class, 'sync'])->name('mailboxes.sync');
    Route::patch('/mailboxes/{mailbox}/reply-identity', [MailboxController::class, 'updateReplyIdentity'])->name('mailboxes.reply-identity.update');
    Route::patch('/mailboxes/{mailbox}/sending', [MailboxController::class, 'updateSending'])->name('mailboxes.sending.update');
    Route::post('/mailboxes/{mailbox}/resume-sending', [MailboxController::class, 'resumeSending'])->name('mailboxes.resume-sending');
    Route::get('/mailboxes/{mailbox}/auth-check', [MailboxController::class, 'authCheck'])->name('mailboxes.auth-check');
    Route::delete('/mailboxes/{mailbox}', [MailboxController::class, 'destroy'])->name('mailboxes.destroy');

    Route::get('/oauth/{provider}/redirect', [OAuthController::class, 'redirect'])->name('oauth.redirect');
    Route::get('/oauth/{provider}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
});

require __DIR__.'/settings.php';
