<?php

use App\Domain\Api\TokenScope;
use App\Mcp\OutbossServer;
use App\Mcp\Tools\CreateConversionDefinition;
use App\Mcp\Tools\GetAccountIntelligence;
use App\Mcp\Tools\GetConversionDefinition;
use App\Mcp\Tools\ListConversionDefinitions;
use App\Models\Account;
use App\Models\ConversionDefinition;
use App\Models\User;
use Laravel\Mcp\Server\Transport\FakeTransporter;

function mcpS2DefinitionPayload(string $key = 'mcp_demo_booked'): array
{
    return [
        'event_key' => $key,
        'display_name' => 'MCP demo booked',
        'description' => 'A bounded MCP test definition.',
        'subject_type' => 'Account',
        'allowed_sources' => ['manual', 'api'],
        'property_schema' => [
            'type' => 'object',
            'properties' => ['plan' => ['type' => 'string']],
            'required' => [],
            'additionalProperties' => false,
        ],
        'allowed_identity_keys' => ['account_id', 'domain'],
        'dedupe_strategy' => 'source_and_external_id',
        'dedupe_configuration' => [],
        'attribution_window' => ['days' => 30],
        'value_policy' => ['allowed' => false, 'currency' => null],
        'correction_policy' => ['allowed' => true],
        'reversal_policy' => ['allowed' => true],
        'retention_policy' => ['unmatched_identity_days' => 30],
        'export_policy' => ['allowed' => true],
    ];
}

it('keeps MCP Account intelligence workspace scoped and parity-safe', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'MCP intelligence account',
        'notes' => 'MCP private note',
    ]);
    $tokenUser = mcpUser($owner, [TokenScope::IntelligenceRead->value]);

    OutbossServer::actingAs($tokenUser)
        ->tool(GetAccountIntelligence::class, ['account_id' => $account->id])
        ->assertOk()
        ->assertSee(['MCP intelligence account', '"account_id":'.$account->id])
        ->assertDontSee('MCP private note');

    OutbossServer::actingAs(mcpUser($owner, [TokenScope::OffersRead->value]))
        ->tool(GetAccountIntelligence::class, ['account_id' => $account->id])
        ->assertHasErrors(['Missing required scope: intelligence:read']);

    $foreign = User::factory()->create();
    OutbossServer::actingAs(mcpUser($foreign, [TokenScope::IntelligenceRead->value]))
        ->tool(GetAccountIntelligence::class, ['account_id' => $account->id])
        ->assertHasErrors();
});

it('creates and reads Conversion Definitions with the conversions scopes', function (): void {
    $owner = User::factory()->create();
    $tokenUser = mcpUser($owner, [TokenScope::ConversionsWrite->value, TokenScope::ConversionsRead->value]);
    $payload = mcpS2DefinitionPayload();

    OutbossServer::actingAs($tokenUser)
        ->tool(CreateConversionDefinition::class, [...$payload, 'idempotency_key' => 'mcp-definition-1'])
        ->assertOk()
        ->assertSee('mcp_demo_booked');

    $definition = ConversionDefinition::query()
        ->where('workspace_id', $owner->currentWorkspace()->id)
        ->where('event_key', 'mcp_demo_booked')
        ->firstOrFail();
    OutbossServer::actingAs($tokenUser)
        ->tool(GetConversionDefinition::class, ['definition_id' => $definition->id])
        ->assertOk()
        ->assertSee(['mcp_demo_booked', (string) $definition->current_version_id]);

    OutbossServer::actingAs(mcpUser($owner, [TokenScope::ConversionsRead->value]))
        ->tool(ListConversionDefinitions::class)
        ->assertOk()
        ->assertSee('mcp_demo_booked');
});

it('does not register an MCP Offer approval or fulfilment tool', function (): void {
    $server = new OutbossServer(new FakeTransporter);
    $names = $server->createContext()->tools()->map(static fn ($tool): string => $tool->name())->all();

    expect($names)
        ->not->toContain('review-offer-version')
        ->not->toContain('approve-offer')
        ->not->toContain('execute-fulfilment');
});
