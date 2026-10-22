<?php

use App\Http\Controllers\Settings\AiProviderController;
use App\Http\Controllers\Settings\ApiKeyController;
use App\Http\Controllers\Settings\DeviceSessionController;
use App\Http\Controllers\Settings\GtmProfileController;
use App\Http\Controllers\Settings\MailExperimentController;
use App\Http\Controllers\Settings\ManagedMailController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/mailboxes');

    Route::get('account/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('account/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::redirect('settings/profile', '/account/profile', 301)->name('profile.legacy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('account/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Managed mail is an internal capability. Scalar IDs are used for these
    // routes deliberately: the capability middleware runs before the
    // controller performs workspace-scoped lookups, so implicit route model
    // binding cannot disclose records from another workspace.
    Route::redirect('settings/mailboxes/managed-mail', '/settings/managed-mail')
        ->middleware('managed-mail')
        ->name('managed-mail.legacy');

    Route::middleware('managed-mail')->prefix('settings/managed-mail')->name('managed-mail.')->group(function (): void {
        Route::get('/', [ManagedMailController::class, 'index'])->name('index');
        // Keep the resource-shaped URL from the implementation plan as the
        // canonical alias while retaining the shorter internal navigation URL.
        Route::get('/domains', [ManagedMailController::class, 'index'])->name('domains.index');
        Route::post('/domains', [ManagedMailController::class, 'storeDomain'])->name('domains.store');
        Route::post('/domains/{domain}/verify', [ManagedMailController::class, 'verifyDomain'])
            ->whereNumber('domain')
            ->name('domains.verify');
        Route::delete('/domains/{domain}', [ManagedMailController::class, 'destroyDomain'])
            ->whereNumber('domain')
            ->name('domains.destroy');
        Route::post('/domains/{domain}/mailboxes', [ManagedMailController::class, 'storeMailbox'])
            ->whereNumber('domain')
            ->name('mailboxes.store');
        Route::post('/mailboxes/{account}/rotate-credential', [ManagedMailController::class, 'rotateCredential'])
            ->whereNumber('account')
            ->name('mailboxes.rotate');
        Route::post('/mailboxes/{account}/suspend', [ManagedMailController::class, 'suspendMailbox'])
            ->whereNumber('account')
            ->name('mailboxes.suspend');
        Route::delete('/mailboxes/{account}', [ManagedMailController::class, 'destroyMailbox'])
            ->whereNumber('account')
            ->name('mailboxes.destroy');
    });

    Route::middleware('managed-mail:mail_experiments')->prefix('settings/mail-experiments')->name('mail-experiments.')->group(function (): void {
        Route::get('/', [MailExperimentController::class, 'index'])->name('index');
        Route::post('/', [MailExperimentController::class, 'store'])->name('store');
        Route::get('/{experiment}', [MailExperimentController::class, 'show'])
            ->whereNumber('experiment')
            ->name('show');
        Route::post('/{experiment}/cohorts/{cohort}/mailboxes', [MailExperimentController::class, 'attachMailboxes'])
            ->whereNumber('experiment')
            ->whereNumber('cohort')
            ->name('cohorts.mailboxes');
        Route::post('/{experiment}/prepare', [MailExperimentController::class, 'prepare'])
            ->whereNumber('experiment')
            ->name('prepare');
        Route::post('/{experiment}/balance', [MailExperimentController::class, 'balance'])
            ->whereNumber('experiment')
            ->name('balance');
        Route::post('/{experiment}/freeze', [MailExperimentController::class, 'freeze'])
            ->whereNumber('experiment')
            ->name('freeze');
        Route::post('/{experiment}/launch', [MailExperimentController::class, 'launch'])
            ->whereNumber('experiment')
            ->name('launch');
        Route::post('/{experiment}/pause', [MailExperimentController::class, 'pause'])
            ->whereNumber('experiment')
            ->name('pause');
        Route::post('/{experiment}/resume', [MailExperimentController::class, 'resume'])
            ->whereNumber('experiment')
            ->name('resume');
        Route::post('/{experiment}/complete', [MailExperimentController::class, 'complete'])
            ->whereNumber('experiment')
            ->name('complete');
        Route::get('/{experiment}/report', [MailExperimentController::class, 'report'])
            ->whereNumber('experiment')
            ->name('report');
        Route::get('/{experiment}/export', [MailExperimentController::class, 'export'])
            ->whereNumber('experiment')
            ->name('export');
    });

    Route::get('account/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');
    Route::redirect('settings/security', '/account/security', 301)->name('security.legacy');

    Route::put('account/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('account/appearance', 'settings/Appearance')->name('appearance.edit');
    Route::redirect('settings/appearance', '/account/appearance', 301)->name('appearance.legacy');

    Route::get('settings/devices', [DeviceSessionController::class, 'index'])->name('devices.index');
    Route::delete('settings/devices/{deviceId}', [DeviceSessionController::class, 'destroy'])
        ->whereUuid('deviceId')
        ->name('devices.destroy');
    Route::get('settings/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('settings/api-keys', [ApiKeyController::class, 'store'])
        ->middleware(RequirePassword::class)
        ->name('api-keys.store');
    Route::delete('settings/api-keys/{tokenId}', [ApiKeyController::class, 'destroy'])
        ->middleware(RequirePassword::class)
        ->whereNumber('tokenId')
        ->name('api-keys.destroy');

    Route::get('settings/ai-research', [AiProviderController::class, 'index'])->name('providers.index');
    Route::get('settings/ai-research/activity', [AiProviderController::class, 'usage'])->name('providers.usage');
    Route::get('settings/ai-research/activity/history', [AiProviderController::class, 'audit'])->name('providers.audit');
    Route::redirect('settings/providers', '/settings/ai-research', 301)->name('providers.legacy');
    Route::redirect('settings/providers/usage', '/settings/ai-research/activity', 301);
    Route::redirect('settings/providers/audit', '/settings/ai-research/activity/history', 301);
    Route::post('settings/providers/{provider}/connect', [AiProviderController::class, 'connect'])
        ->middleware([RequirePassword::class, 'throttle:6,1'])
        ->name('providers.connect');
    Route::post('settings/providers/{credential}/test', [AiProviderController::class, 'test'])
        ->middleware('throttle:12,1')
        ->name('providers.test');
    Route::put('settings/providers/{credential}/rotate', [AiProviderController::class, 'rotate'])
        ->middleware([RequirePassword::class, 'throttle:6,1'])
        ->name('providers.rotate');
    Route::patch('settings/providers/{credential}/status', [AiProviderController::class, 'status'])
        ->middleware(RequirePassword::class)
        ->name('providers.status');
    Route::delete('settings/providers/{credential}', [AiProviderController::class, 'destroy'])
        ->middleware(RequirePassword::class)
        ->name('providers.destroy');
    Route::put('settings/providers/models/{task}', [AiProviderController::class, 'updateTask'])
        ->name('providers.models.update');
    Route::put('settings/providers/budget', [AiProviderController::class, 'updateBudget'])
        ->name('providers.budget.update');

    Route::get('settings/gtm-profile', [GtmProfileController::class, 'show'])
        ->name('gtm-profile.show');
    Route::patch('settings/gtm-profile/sections/{section}', [GtmProfileController::class, 'updateSection'])
        ->middleware('throttle:gtm-autosave')
        ->whereIn('section', ['company', 'market', 'buyers', 'messaging', 'qualification'])
        ->name('gtm-profile.sections.update');
    Route::put('settings/gtm-profile/website', [GtmProfileController::class, 'updateWebsite'])
        ->middleware('throttle:gtm-mutations')
        ->name('gtm-profile.website.update');
    Route::post('settings/gtm-profile/research', [GtmProfileController::class, 'research'])
        ->middleware('throttle:gtm-research')
        ->name('gtm-profile.research.store');
    Route::post('settings/gtm-profile/review/{item}', [GtmProfileController::class, 'resolveReview'])
        ->middleware('throttle:gtm-mutations')
        ->name('gtm-profile.review.resolve');
    Route::get('settings/gtm-profile/sources', [GtmProfileController::class, 'sources'])
        ->name('gtm-profile.sources');
    Route::get('settings/gtm-profile/history', [GtmProfileController::class, 'history'])
        ->name('gtm-profile.history');
    Route::get('settings/gtm-profile/export', [GtmProfileController::class, 'exportProfile'])
        ->middleware('throttle:gtm-export')
        ->name('gtm-profile.export');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
