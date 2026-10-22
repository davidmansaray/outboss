<?php

use App\Domain\Workspaces\Services\ProvisionWorkspace;
use App\Domain\Workspaces\Services\PublicWebsiteAddressResolver;
use App\Domain\Workspaces\Services\WorkspaceAvatarManager;
use App\Domain\Workspaces\Services\WorkspaceFaviconFetcher;
use App\Jobs\Workspaces\FetchWorkspaceAvatar;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

beforeEach(function () {
    Queue::fake();
    Storage::fake('public');
    Http::preventStrayRequests();

    $resolver = Mockery::mock(PublicWebsiteAddressResolver::class);
    $resolver->shouldReceive('resolve')->byDefault()->andReturn(['93.184.216.34']);
    app()->instance(PublicWebsiteAddressResolver::class, $resolver);
});

it('discovers, validates, and caches a declared favicon on the public disk', function () {
    $workspace = workspaceWithWebsite('https://example.com');
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );

    Http::fake([
        'https://example.com' => Http::response(
            '<html><head><link rel="icon" sizes="64x64" href="/brand/icon.png"></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
        'https://example.com/brand/icon.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
    ]);

    app(WorkspaceFaviconFetcher::class)->refresh(
        $workspace->id,
        app(WorkspaceAvatarManager::class)->websiteHash('https://example.com'),
    );

    $workspace->refresh();
    expect($workspace->avatar_path)->toStartWith("workspace-avatars/{$workspace->id}/")
        ->and($workspace->avatar_path)->toEndWith('.png')
        ->and($workspace->avatar_source_url)->toBe('https://example.com/brand/icon.png')
        ->and($workspace->avatar_website_hash)->toBe(hash('sha256', 'https://example.com'))
        ->and($workspace->avatar_refreshed_at)->not->toBeNull();
    Storage::disk('public')->assertExists($workspace->avatar_path);
});

it('records a completed attempt and leaves the initial fallback when no valid icon exists', function () {
    $workspace = workspaceWithWebsite('https://example.com');

    Http::fake([
        'https://example.com' => Http::response('<html><head></head></html>', 200, ['Content-Type' => 'text/html']),
        'https://example.com/favicon.ico' => Http::response(
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            200,
            ['Content-Type' => 'image/svg+xml'],
        ),
    ]);

    app(WorkspaceFaviconFetcher::class)->refresh($workspace->id, hash('sha256', 'https://example.com'));

    $workspace->refresh();
    expect($workspace->avatar_path)->toBeNull()
        ->and($workspace->avatar_source_url)->toBeNull()
        ->and($workspace->avatar_website_hash)->toBe(hash('sha256', 'https://example.com'))
        ->and($workspace->avatar_refreshed_at)->not->toBeNull()
        ->and($workspace->avatar_missing_since)->not->toBeNull();
});

it('sanitizes and caches an ordinary SVG favicon', function () {
    $workspace = workspaceWithWebsite('https://example.com');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" fill="#17151f"/><path d="M2 2h20v20H2z"/></svg>';

    Http::fake([
        'https://example.com' => Http::response(
            '<link rel="icon" href="/assets/favicon.svg">',
            200,
            ['Content-Type' => 'text/html'],
        ),
        'https://example.com/assets/favicon.svg' => Http::response($svg, 200, ['Content-Type' => 'image/svg+xml']),
    ]);

    app(WorkspaceFaviconFetcher::class)->refresh($workspace->id, hash('sha256', 'https://example.com'));

    $workspace->refresh();
    expect($workspace->avatar_path)->toEndWith('.svg')
        ->and(Storage::disk('public')->get($workspace->avatar_path))->toContain('<rect');
});

it('does not fetch or overwrite an avatar for a stale website job', function () {
    $workspace = workspaceWithWebsite('https://example.com');

    app(WorkspaceFaviconFetcher::class)->refresh($workspace->id, hash('sha256', 'https://old.example.com'));

    Http::assertNothingSent();
    expect($workspace->fresh()->avatar_refreshed_at)->toBeNull();
});

it('rejects a redirect when its destination resolves to a non-public address', function () {
    $workspace = workspaceWithWebsite('https://example.com');
    $resolver = Mockery::mock(PublicWebsiteAddressResolver::class);
    $resolver->shouldReceive('resolve')->with('https://example.com')->andReturn(['93.184.216.34']);
    $resolver->shouldReceive('resolve')->with('https://private.example.com/icon.png')
        ->andThrow(new InvalidArgumentException('The website must resolve to a public network address.'));
    $resolver->shouldReceive('resolve')->with('https://example.com/favicon.ico')->andReturn(['93.184.216.34']);
    app()->instance(PublicWebsiteAddressResolver::class, $resolver);

    Http::fake(function (Request $request) {
        return match ($request->url()) {
            'https://example.com' => Http::response('', 302, ['Location' => 'https://private.example.com/icon.png']),
            'https://example.com/favicon.ico' => Http::response('', 404),
            default => Http::response('', 500),
        };
    });

    app(WorkspaceFaviconFetcher::class)->refresh($workspace->id, hash('sha256', 'https://example.com'));

    expect($workspace->fresh()->avatar_path)->toBeNull();
    Http::assertSentCount(2);
});

it('dispatches one favicon refresh for a newly provisioned website and none for its replay', function () {
    $user = User::factory()->withoutWorkspace()->create();
    $provisioner = app(ProvisionWorkspace::class);

    $workspace = $provisioner->create($user, 'Branded workspace', 'team', 'example.com', 'avatar-operation');
    $provisioner->create($user, 'Branded workspace', 'team', 'https://example.com', 'avatar-operation');

    Queue::assertPushed(FetchWorkspaceAvatar::class, 1);
    Queue::assertPushed(fn (FetchWorkspaceAvatar $job): bool => $job->workspaceId === $workspace->id
        && $job->expectedWebsiteHash === hash('sha256', 'https://example.com'));
});

it('clears cached identity immediately when the canonical website is removed', function () {
    $workspace = workspaceWithWebsite('https://example.com');
    $path = "workspace-avatars/{$workspace->id}/old.png";
    Storage::disk('public')->put($path, 'old icon');
    $workspace->forceFill([
        'avatar_path' => $path,
        'avatar_source_url' => 'https://example.com/favicon.png',
        'avatar_website_hash' => hash('sha256', 'https://example.com'),
        'avatar_refreshed_at' => now(),
    ])->save();

    app(WorkspaceAvatarManager::class)->websiteChanged($workspace, null);

    $workspace->refresh();
    expect($workspace->avatar_path)->toBeNull()
        ->and($workspace->avatar_source_url)->toBeNull()
        ->and($workspace->avatar_website_hash)->toBeNull()
        ->and($workspace->avatar_refreshed_at)->toBeNull()
        ->and($workspace->avatar_missing_since)->toBeNull();
});

it('backfills only workspace avatars that have a canonical website', function () {
    $withWebsite = workspaceWithWebsite('https://example.com');
    $owner = User::factory()->withoutWorkspace()->create();
    app(ProvisionWorkspace::class)->create($owner, 'No website workspace');
    Queue::fake();

    $this->artisan('workspaces:refresh-avatars', ['--missing' => true])
        ->expectsOutput('Scanned 2 workspace(s): queued 1 avatar refresh(es), skipped 1 without a website.')
        ->assertExitCode(0);

    Queue::assertPushed(FetchWorkspaceAvatar::class, fn (FetchWorkspaceAvatar $job): bool => $job->workspaceId === $withWebsite->id);
});

it('retries recent missing icons daily and all other icon states weekly', function () {
    $recentMissing = workspaceWithWebsite('https://example.com');
    $longMissing = workspaceWithWebsite('https://example.com');
    $recentIcon = workspaceWithWebsite('https://example.com');
    $staleIcon = workspaceWithWebsite('https://example.com');
    $neverAttempted = workspaceWithWebsite('https://example.com');
    $now = now();

    $recentMissing->forceFill([
        'avatar_missing_since' => $now->copy()->subDays(2),
        'avatar_refreshed_at' => $now->copy()->subDay(),
    ])->save();
    $longMissing->forceFill([
        'avatar_missing_since' => $now->copy()->subDays(8),
        'avatar_refreshed_at' => $now->copy()->subDay(),
    ])->save();
    $recentIcon->forceFill([
        'avatar_path' => "workspace-avatars/{$recentIcon->id}/recent.png",
        'avatar_refreshed_at' => $now->copy()->subDay(),
    ])->save();
    $staleIcon->forceFill([
        'avatar_path' => "workspace-avatars/{$staleIcon->id}/stale.png",
        'avatar_refreshed_at' => $now->copy()->subDays(8),
    ])->save();
    Queue::fake();

    $this->artisan('workspaces:refresh-avatars', ['--routine' => true])
        ->expectsOutput('Scanned 3 workspace(s): queued 3 avatar refresh(es), skipped 0 without a website.')
        ->assertExitCode(0);

    Queue::assertPushed(FetchWorkspaceAvatar::class, 3);
    foreach ([$recentMissing, $staleIcon, $neverAttempted] as $workspace) {
        Queue::assertPushed(
            FetchWorkspaceAvatar::class,
            fn (FetchWorkspaceAvatar $job): bool => $job->workspaceId === $workspace->id,
        );
    }
    foreach ([$longMissing, $recentIcon] as $workspace) {
        Queue::assertNotPushed(
            FetchWorkspaceAvatar::class,
            fn (FetchWorkspaceAvatar $job): bool => $job->workspaceId === $workspace->id,
        );
    }
});

function workspaceWithWebsite(string $website): Workspace
{
    $user = User::factory()->withoutWorkspace()->create();

    return app(ProvisionWorkspace::class)->create($user, 'Example workspace', 'team', $website);
}
