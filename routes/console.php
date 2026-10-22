<?php

use App\Domain\Campaigns\Services\ExpireRecipientImport;
use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Domain\Conversions\Services\ExpireUnmatchedConversionIdentity;
use App\Domain\Devices\Services\PruneDeviceAuthRecords;
use App\Domain\Gtm\Services\SweepGtmProfileResearchDispatches;
use App\Domain\Intelligence\Services\RecoverAccountResearchRuns;
use App\Domain\Intelligence\Services\SweepAccountResearchDispatches;
use App\Domain\Mail\Services\PublishOutboxEvents;
use App\Domain\Mail\Services\ReconcileUnknownSend;
use App\Domain\Mail\Services\SweepStalledAttempts;
use App\Domain\Objectives\Services\ReconcileObjectiveAttribution;
use App\Domain\Objectives\Services\ReconcileObjectiveConversations;
use App\Domain\Objectives\Services\ReconcileObjectiveExecution;
use App\Domain\Objectives\Services\RecoverObjectiveRuns;
use App\Domain\Objectives\Services\SweepObjectiveRunDispatches;
use App\Domain\Prospecting\Services\ReconcileProspectSources;
use App\Jobs\Ai\ReconcileAiProviderOperations;
use App\Jobs\Integrations\ReconcileExternalOperations;
use App\Jobs\Intelligence\ExpireResearchSourceSnapshots;
use App\Jobs\Mail\SyncMailbox;
use App\Jobs\Prospecting\ExpireSourceObservations;
use App\Jobs\Prospecting\SweepStalledSourceRuns;
use App\Models\Mailbox;
use App\Models\Workspace;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(ScheduleDueCampaignSends::class)->handle())
    ->everyMinute()
    ->name('outboss-schedule-sends')
    ->withoutOverlapping(5);

Schedule::call(function (): void {
    app(PublishOutboxEvents::class)->handle();
    app(SweepStalledAttempts::class)->handle();
})->everyMinute()->name('outboss-outbox-sweep')->withoutOverlapping(5);

Schedule::call(fn () => app(SweepGtmProfileResearchDispatches::class)->handle())
    ->everyMinute()
    ->name('outboss-gtm-research-dispatch-sweep')
    ->withoutOverlapping(5);

Schedule::call(fn () => app(SweepAccountResearchDispatches::class)->handle())
    ->everyMinute()
    ->name('outboss-account-research-dispatch-sweep')
    ->withoutOverlapping(5);

Schedule::call(fn () => app(RecoverAccountResearchRuns::class)->handle())
    ->everyMinute()
    ->name('outboss-account-research-recovery')
    ->withoutOverlapping(5);

Schedule::call(fn () => app(SweepObjectiveRunDispatches::class)->handle())
    ->everyMinute()
    ->name('outboss-objective-dispatch-sweep')
    ->withoutOverlapping(5);

Schedule::call(fn () => app(RecoverObjectiveRuns::class)->handle())
    ->everyMinute()
    ->name('outboss-objective-run-recovery')
    ->withoutOverlapping(5);

Schedule::call(function (): void {
    app(ReconcileObjectiveExecution::class)->handle(limit: 100);
    app(ReconcileObjectiveConversations::class)->handle(limit: 100);
    app(ReconcileObjectiveAttribution::class)->handle(limit: 100);
})
    ->everyMinute()
    ->name('outboss-objective-outcome-recovery')
    ->withoutOverlapping(5);

Schedule::call(fn () => app(ExpireUnmatchedConversionIdentity::class)->handle())
    ->everyFiveMinutes()
    ->name('outboss-expire-unmatched-conversion-identities')
    ->withoutOverlapping(10);

Schedule::call(function (): void {
    Mailbox::query()
        ->where('status', 'connected')
        ->orderBy('id')
        ->each(fn (Mailbox $mailbox) => SyncMailbox::dispatch($mailbox->id)->delay(now()->addSeconds($mailbox->id % 30)));
})->everyTwoMinutes()->name('outboss-sync-mailboxes')->withoutOverlapping(10);

Schedule::call(fn () => app(ReconcileUnknownSend::class)->handle())
    ->everyFiveMinutes()
    ->name('outboss-reconcile-unknown')
    ->withoutOverlapping(10);

Schedule::job(new ReconcileAiProviderOperations)
    ->everyFiveMinutes()
    ->name('outboss-reconcile-ai-provider-operations')
    ->withoutOverlapping(10);

Schedule::job(new ReconcileExternalOperations)
    ->everyFiveMinutes()
    ->name('outboss-reconcile-external-operations')
    ->withoutOverlapping(10);

Schedule::call(fn () => app(ExpireRecipientImport::class)->handle())
    ->hourly()
    ->name('outboss-expire-imports')
    ->withoutOverlapping(120);

// Retention work is dispatched once per workspace.  The job payload contains
// only the workspace ID, and the service performs the policy-aware observation
// filtering in the worker.
Schedule::call(function (): void {
    Workspace::query()
        ->orderBy('id')
        ->pluck('id')
        ->each(fn ($workspaceId) => ExpireSourceObservations::dispatch((int) $workspaceId));
})->dailyAt('02:15')->name('outboss-expire-source-observations')->withoutOverlapping(120);

// S2 source bodies are retained independently from S1 observations. Dispatch
// one bounded, ID-only pass per workspace so a retry cannot cross tenant
// boundaries or load raw source content into scheduler state.
Schedule::call(function (): void {
    Workspace::query()
        ->orderBy('id')
        ->pluck('id')
        ->each(fn ($workspaceId) => ExpireResearchSourceSnapshots::dispatch((int) $workspaceId));
})->dailyAt('02:30')->name('outboss-expire-research-source-snapshots')->withoutOverlapping(120);

// A sweep only finds expired leases; each state transition is re-checked by a
// scoped recovery job under a row lock.
Schedule::job(new SweepStalledSourceRuns)
    ->everyMinute()
    ->name('outboss-sweep-stalled-source-runs')
    ->withoutOverlapping(5);

Schedule::call(fn () => app(ReconcileProspectSources::class)->handle(null, true))
    ->everyFiveMinutes()
    ->name('outboss-reconcile-prospect-sources')
    ->withoutOverlapping(10);

Schedule::command('workspaces:refresh-avatars', ['--routine' => true])
    ->dailyAt('03:17')
    ->name('outboss-refresh-workspace-avatars')
    ->withoutOverlapping(60);

// iOS sign-in: drop expired access tokens, spent sign-in codes, expired
// refresh tokens and long-revoked device sessions.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])
    ->dailyAt('03:41')
    ->name('outboss-prune-expired-tokens')
    ->withoutOverlapping(30);

Schedule::call(fn () => app(PruneDeviceAuthRecords::class)->handle())
    ->dailyAt('03:47')
    ->name('outboss-prune-device-auth')
    ->withoutOverlapping(30);
