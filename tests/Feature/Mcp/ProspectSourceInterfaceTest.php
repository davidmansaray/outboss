<?php

use App\Domain\Api\TokenScope;
use App\Domain\People\Support\ProspectSourceSurface;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Mcp\OutbossServer;
use App\Mcp\Resources\ProspectSourcesResource;
use App\Mcp\Tools\CreateProspectSource as CreateProspectSourceTool;
use App\Mcp\Tools\GetProspectSource;
use App\Mcp\Tools\ListProspectSources;
use App\Mcp\Tools\ListSourceRunObservations;
use App\Mcp\Tools\StartProspectSourceRun;
use App\Models\CanonicalFieldProvenance;
use App\Models\ProspectSource;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Workspace;

/**
 * @return array{owner: User, workspace: Workspace, source: ProspectSource}
 */
function makeMcpSource(): array
{
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $source = app(CreateProspectSource::class)->handle(
        workspace: $workspace,
        kind: 'api_import',
        displayName: 'MCP source',
        configuration: ['ingress' => 'mcp', 'system_managed' => false],
        actor: $owner,
        origin: 'mcp',
    );

    return compact('owner', 'workspace', 'source');
}

/**
 * @param  list<string>  $scopes
 */
function bindMcpTokenToWorkspace(User $user, Workspace $workspace, array $scopes): User
{
    mcpUser($user, $scopes)->currentAccessToken()?->forceFill([
        'workspace_id' => $workspace->id,
    ])->save();

    return $user;
}

it('lists and gets only the authenticated workspace sources through MCP', function (): void {
    ['owner' => $owner, 'source' => $source] = makeMcpSource();
    $foreign = User::factory()->create();
    $foreignSource = app(CreateProspectSource::class)->handle(
        workspace: $foreign->currentWorkspace(),
        kind: 'manual',
        displayName: 'Secret MCP source',
        actor: $foreign,
        origin: 'mcp',
    );

    OutbossServer::actingAs(mcpUser($owner, [TokenScope::SourcesRead->value]))
        ->tool(ListProspectSources::class)
        ->assertOk()
        ->assertSee('MCP source')
        ->assertDontSee('Secret MCP source');

    OutbossServer::actingAs(mcpUser($owner, [TokenScope::SourcesRead->value]))
        ->tool(GetProspectSource::class, ['source_id' => $source->id])
        ->assertOk()
        ->assertSee(['MCP source', (string) $source->current_version_id])
        ->assertDontSee('Secret MCP source');

    OutbossServer::actingAs(mcpUser($owner, [TokenScope::SourcesRead->value]))
        ->tool(GetProspectSource::class, ['source_id' => $foreignSource->id])
        ->assertHasErrors(['Prospect source not found.']);

    OutbossServer::actingAs(mcpUser($owner, [TokenScope::SourcesRead->value]))
        ->resource(ProspectSourcesResource::class)
        ->assertOk()
        ->assertSee('MCP source')
        ->assertDontSee('Secret MCP source');
});

it('enforces source scopes and owner access for MCP source mutations', function (): void {
    ['owner' => $owner, 'workspace' => $workspace, 'source' => $source] = makeMcpSource();
    $member = User::factory()->create();
    $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

    OutbossServer::actingAs(mcpUser($owner, [TokenScope::PeopleRead->value]))
        ->tool(ListProspectSources::class)
        ->assertHasErrors(['Missing required scope: sources:read']);

    $memberRead = bindMcpTokenToWorkspace($member, $workspace, [TokenScope::SourcesRead->value]);
    OutbossServer::actingAs($memberRead)
        ->tool(ListProspectSources::class)
        ->assertOk()
        ->assertSee('MCP source');

    $memberWrite = bindMcpTokenToWorkspace($member, $workspace, [TokenScope::SourcesWrite->value]);
    OutbossServer::actingAs($memberWrite)
        ->tool(CreateProspectSourceTool::class, [
            'display_name' => 'Member MCP source',
            'kind' => 'manual',
        ])
        ->assertHasErrors(['Only workspace owners can manage prospect sources.']);

    expect(ProspectSource::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and($source->fresh()->display_name)->toBe('MCP source');
});

it('creates and starts an MCP source run through the ingestion path with provenance', function (): void {
    $owner = User::factory()->create();
    $tokenUser = mcpUser($owner, [TokenScope::SourcesRead->value, TokenScope::SourcesWrite->value]);

    OutbossServer::actingAs($tokenUser)
        ->tool(CreateProspectSourceTool::class, [
            'display_name' => 'Created by MCP',
            'kind' => 'api_import',
            'configuration' => ['ingress' => 'mcp', 'system_managed' => false],
            'policy' => ['display_classification' => 'restricted'],
        ])
        ->assertOk()
        ->assertSee('Created by MCP');
    $source = ProspectSource::query()
        ->where('workspace_id', $owner->currentWorkspace()->id)
        ->where('display_name', 'Created by MCP')
        ->firstOrFail();

    expect($source->currentVersion()->firstOrFail()->origin->value)->toBe('mcp')
        ->and($source->currentVersion()->firstOrFail()->actor_user_id)->toBe($owner->id);

    OutbossServer::actingAs($tokenUser)
        ->tool(StartProspectSourceRun::class, [
            'source_id' => $source->id,
            'idempotency_key' => 'mcp-source-run-1',
            'input_reference' => ['transport' => 'mcp'],
            'input' => [[
                'entity_type' => 'person',
                'row_number' => 1,
                'observation_key' => 'mcp-person-1',
                'provider_record_id' => 'mcp-private-provider-id',
                'observed_values' => [
                    'email' => 'maya@mcp.example',
                    'full_name' => 'Maya MCP',
                ],
            ]],
        ])
        ->assertOk()
        ->assertSee('completed');

    $run = SourceRun::query()
        ->where('prospect_source_id', $source->id)
        ->firstOrFail();
    $observation = SourceObservation::query()
        ->where('source_run_id', $run->id)
        ->firstOrFail();

    expect($run->initiator_type)->toBe('mcp')
        ->and($run->initiated_by_user_id)->toBe($owner->id)
        ->and($observation->provider_record_id)->toBe('mcp-private-provider-id')
        ->and(CanonicalFieldProvenance::query()
            ->where('workspace_id', $owner->currentWorkspace()->id)
            ->where('source_observation_id', $observation->id)
            ->exists())->toBeTrue();

    OutbossServer::actingAs($tokenUser)
        ->tool(GetProspectSource::class, ['source_id' => $source->id])
        ->assertOk()
        ->assertSee([(string) $run->id, (string) $source->current_version_id]);

    OutbossServer::actingAs($tokenUser)
        ->tool(ListSourceRunObservations::class, ['run_id' => $run->id])
        ->assertOk()
        ->assertSee(['exact_email', 'restricted', 'restricted_by_source_policy'])
        ->assertDontSee(['mcp-private-provider-id', 'maya@mcp.example', 'Maya MCP']);

    $surfaceObservation = ProspectSourceSurface::observations($owner->currentWorkspace(), $run->id)['data'][0];
    expect($surfaceObservation)
        ->not->toHaveKey('observed_values')
        ->and($surfaceObservation['account_id'])->toBeNull()
        ->and($surfaceObservation['person_id'])->toBeNull()
        ->and($surfaceObservation['display_classification'])->toBe('restricted')
        ->and($surfaceObservation['display_omission_reason'])->toBe('restricted_by_source_policy');
});
