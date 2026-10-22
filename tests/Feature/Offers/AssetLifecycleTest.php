<?php

use App\Domain\Api\TokenScope;
use App\Domain\Gtm\Services\ArchiveAsset;
use App\Domain\Gtm\Services\CreateAsset;
use App\Domain\Gtm\Services\VersionAsset;
use App\Mcp\OutbossServer;
use App\Mcp\Tools\GetAsset;
use App\Mcp\Tools\ListAssets;
use App\Mcp\Tools\ProposeAssetVersion;
use App\Models\AssetVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

function assetLifecycleInput(array $overrides = []): array
{
    return array_replace([
        'title' => 'A bounded Asset',
        'description' => 'A safe external deliverable.',
        'external_url' => 'https://assets.example.test/guide.pdf',
        'media_type' => 'application/pdf',
        'source' => 'owner',
        'approval_state' => 'draft',
        'availability_state' => 'available',
        'classification' => 'public',
    ], $overrides);
}

it('creates immutable Asset versions, archives without deleting history, and replays operations', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $asset = app(CreateAsset::class)->handle($workspace, $owner, assetLifecycleInput(), 'asset-create');
    $firstVersion = $asset->currentVersion;

    $updated = app(VersionAsset::class)->handle(
        $asset,
        $owner,
        ['title' => 'A revised bounded Asset'],
        0,
        'asset-version',
    );
    $replayed = app(VersionAsset::class)->handle(
        $asset,
        $owner,
        ['title' => 'A revised bounded Asset'],
        0,
        'asset-version',
    );

    expect($updated->lock_version)->toBe(1)
        ->and($updated->currentVersion->version)->toBe(2)
        ->and($replayed->current_version_id)->toBe($updated->current_version_id)
        ->and(AssetVersion::query()->where('asset_id', $asset->id)->count())->toBe(2)
        ->and($firstVersion->fresh()->title)->toBe('A bounded Asset');

    $latest = app(VersionAsset::class)->handle(
        $updated,
        $owner,
        ['title' => 'The later Asset change'],
        1,
        'asset-version-later',
    );
    $olderOperationReplay = app(VersionAsset::class)->handle(
        $latest,
        $owner,
        ['title' => 'A revised bounded Asset'],
        0,
        'asset-version',
    );
    expect($olderOperationReplay->current_version_id)->toBe($updated->current_version_id)
        ->and($olderOperationReplay->lock_version)->toBe(1)
        ->and(AssetVersion::query()->where('asset_id', $asset->id)->count())->toBe(3);

    $archived = app(ArchiveAsset::class)->handle($latest, $owner, 2, 'asset-archive');
    $archiveReplay = app(ArchiveAsset::class)->handle($latest, $owner, 2, 'asset-archive');
    expect($archived->status)->toBe('archived')
        ->and($archived->lock_version)->toBe(3)
        ->and($archiveReplay->status)->toBe('archived')
        ->and($archiveReplay->current_version_id)->toBe($latest->current_version_id)
        ->and(AssetVersion::query()->where('asset_id', $asset->id)->count())->toBe(3);

    expect(fn () => app(VersionAsset::class)->handle(
        $archived,
        $owner,
        ['title' => 'Cannot change archived Asset'],
        3,
        'asset-after-archive',
    ))->toThrow(ValidationException::class);
});

it('rejects stale and conflicting Asset operations and preserves workspace ownership', function (): void {
    $owner = User::factory()->create();
    $asset = app(CreateAsset::class)->handle($owner->currentWorkspace(), $owner, assetLifecycleInput(), 'asset-create');
    $versioned = app(VersionAsset::class)->handle($asset, $owner, ['title' => 'First change'], 0, 'asset-version');

    expect(fn () => app(VersionAsset::class)->handle(
        $versioned,
        $owner,
        ['title' => 'Stale change'],
        0,
        'different-operation',
    ))->toThrow(RuntimeException::class, 'stale_version')
        ->and(fn () => app(VersionAsset::class)->handle(
            $versioned,
            $owner,
            ['title' => 'Conflicting replay'],
            0,
            'asset-version',
        ))->toThrow(RuntimeException::class, 'operation_replay_mismatch');

    $foreign = User::factory()->create();
    expect(fn () => app(VersionAsset::class)->handle(
        $asset,
        $foreign,
        ['title' => 'Foreign change'],
        0,
        'foreign-operation',
    ))->toThrow(AuthorizationException::class);
});

it('exposes only bounded workspace-safe Assets over REST and enforces owner writes and scopes', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $asset = app(CreateAsset::class)->handle($workspace, $owner, assetLifecycleInput([
        'classification' => 'restricted',
        'description' => 'restricted description must not cross the boundary',
        'external_url' => 'https://private.example.test/raw.pdf',
        'access_instructions' => ['secret-access-token'],
        'delivery_restrictions' => ['internal-only'],
        'tracking_metadata' => ['raw-provider-payload'],
    ]), 'asset-create');
    $readToken = apiToken($owner, [TokenScope::OffersRead->value]);
    $this->withToken($readToken)->getJson('/api/v1/assets')
        ->assertOk()
        ->assertJsonPath('data.0.id', $asset->id)
        ->assertJsonPath('data.0.current_version.classification', 'restricted')
        ->assertJsonMissingPath('data.0.current_version.external_url')
        ->assertJsonMissingPath('data.0.current_version.access_instructions')
        ->assertJsonMissingPath('data.0.current_version.tracking_metadata')
        ->assertDontSee('restricted description must not cross the boundary');

    $member = User::factory()->create();
    $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
    $memberToken = apiToken($member, [TokenScope::OffersRead->value, TokenScope::OffersWrite->value]);

    $this->withToken($memberToken)->postJson('/api/v1/assets/'.$asset->id.'/versions', [
        'title' => 'Member cannot change this',
        'expected_lock_version' => 0,
        'client_operation_id' => 'member-version',
    ], ['Idempotency-Key' => 'member-version'])->assertForbidden();

    $this->withToken($memberToken)->getJson('/api/v1/assets/'.$asset->id)->assertOk();
    $this->withToken($memberToken)->postJson('/api/v1/assets/'.$asset->id.'/archive', [
        'expected_lock_version' => 0,
        'client_operation_id' => 'member-archive',
    ], ['Idempotency-Key' => 'member-archive'])->assertForbidden();
});

it('keeps Asset writes available while MCP reads and draft proposals honour scopes', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $assetPayload = assetLifecycleInput();
    $writeToken = apiToken($owner, [TokenScope::OffersWrite->value]);
    $this->withToken($writeToken)->postJson('/api/v1/assets', $assetPayload, [
        'Idempotency-Key' => 'rest-asset-create',
    ])->assertCreated();

    $asset = app(CreateAsset::class)->handle($workspace, $owner, $assetPayload, 'mcp-asset-create');
    OutbossServer::actingAs(mcpUser($owner, [TokenScope::OffersRead->value]))
        ->tool(ListAssets::class)
        ->assertOk()
        ->assertSee('A bounded Asset');
    OutbossServer::actingAs(mcpUser($owner, [TokenScope::OffersRead->value]))
        ->tool(GetAsset::class, ['asset_id' => $asset->id])
        ->assertOk()
        ->assertSee((string) $asset->current_version_id);
    OutbossServer::actingAs(mcpUser($owner, [TokenScope::OffersRead->value]))
        ->tool(ProposeAssetVersion::class, [
            'asset_id' => $asset->id,
            'changes' => ['title' => 'Should be denied'],
            'expected_lock_version' => 0,
            'operation_id' => 'mcp-version-denied',
        ])
        ->assertHasErrors(['Missing required scope: offers:write']);
    OutbossServer::actingAs(mcpUser($owner, [TokenScope::OffersWrite->value]))
        ->tool(ProposeAssetVersion::class, [
            'asset_id' => $asset->id,
            'changes' => ['title' => 'MCP proposed draft'],
            'expected_lock_version' => 0,
            'operation_id' => 'mcp-version',
        ])
        ->assertOk()
        ->assertSee(['MCP proposed draft', '"proposed":true']);
});
