<?php

namespace App\Domain\Integrations\Connections;

use App\Domain\Integrations\DTOs\CapabilityDefinition;
use App\Domain\Integrations\Exceptions\CapabilityUnavailable;
use App\Domain\Integrations\Support\CapabilityCatalogue;
use App\Models\IntegrationCapabilityRoute;
use App\Models\IntegrationConnection;
use App\Models\IntegrationProviderRegistration;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RegistrationManager
{
    public function __construct(private readonly CapabilityCatalogue $catalogue) {}

    public function register(
        Workspace $workspace,
        IntegrationConnection $connection,
        CapabilityDefinition|string $capability,
        ?User $actor = null,
        string $adapterVersion = '1',
        int $pricingVersion = 1,
        int $policyVersion = 1,
        bool $enabled = true,
    ): IntegrationProviderRegistration {
        if ($actor !== null && $workspace->membershipFor($actor) === null) {
            throw new RuntimeException('The actor is not a member of this workspace.');
        }
        if ((int) $connection->workspace_id !== (int) $workspace->id) {
            throw new RuntimeException('The connection belongs to another workspace.');
        }
        $definition = $capability instanceof CapabilityDefinition ? $capability : $this->catalogue->get($capability);
        $providerConfig = config("integrations.providers.{$connection->provider}", []);
        if (! is_array($providerConfig)
            || ! in_array($definition->name, $providerConfig['capabilities'] ?? [], true)
            || ! ($providerConfig['installed'] ?? false)
            || ! ($providerConfig['enabled'] ?? false)) {
            throw new CapabilityUnavailable($definition->name, (string) ($providerConfig['availability_reason'] ?? 'provider_unavailable'));
        }

        return DB::transaction(function () use ($workspace, $connection, $definition, $actor, $adapterVersion, $pricingVersion, $policyVersion, $enabled): IntegrationProviderRegistration {
            $registration = IntegrationProviderRegistration::query()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'connection_id' => $connection->id,
                    'capability' => $definition->name,
                    'adapter_version' => $adapterVersion,
                ],
                [
                    'provider' => $connection->provider,
                    'capability_schema_version' => $definition->schemaVersion,
                    'pricing_version' => $pricingVersion,
                    'policy_version' => $policyVersion,
                    'code_definition_hash' => $this->catalogue->definitionHash($definition),
                    'coverage' => [],
                    'limits' => [],
                    'enabled' => $enabled,
                    'enabled_at' => $enabled ? now() : null,
                    'created_by_user_id' => $actor?->id,
                    'updated_by_user_id' => $actor?->id,
                ],
            );

            if ($registration->wasRecentlyCreated === false) {
                $registration->forceFill([
                    'enabled' => $enabled,
                    'enabled_at' => $enabled ? ($registration->enabled_at ?? now()) : null,
                    'updated_by_user_id' => $actor?->id,
                ])->save();
            }

            return $registration->fresh();
        }, 3);
    }

    public function route(
        Workspace $workspace,
        IntegrationProviderRegistration $registration,
        ?User $actor = null,
    ): IntegrationCapabilityRoute {
        if ((int) $registration->workspace_id !== (int) $workspace->id) {
            throw new RuntimeException('The registration belongs to another workspace.');
        }
        if (! $registration->enabled) {
            throw new CapabilityUnavailable($registration->capability, 'registration_disabled');
        }
        if ($actor !== null && $workspace->membershipFor($actor) === null) {
            throw new RuntimeException('The actor is not a member of this workspace.');
        }

        return DB::transaction(function () use ($workspace, $registration, $actor): IntegrationCapabilityRoute {
            $route = IntegrationCapabilityRoute::query()
                ->where('workspace_id', $workspace->id)
                ->where('capability', $registration->capability)
                ->lockForUpdate()
                ->first();
            $route ??= IntegrationCapabilityRoute::query()->create([
                'workspace_id' => $workspace->id,
                'capability' => $registration->capability,
                'active_registration_id' => $registration->id,
                'status' => 'active',
                'version' => 1,
                'updated_by_user_id' => $actor?->id,
            ]);
            if (! $route->wasRecentlyCreated) {
                $route->forceFill([
                    'active_registration_id' => $registration->id,
                    'status' => 'active',
                    'version' => (int) $route->version + 1,
                    'updated_by_user_id' => $actor?->id,
                ])->save();
            }

            return $route->fresh();
        }, 3);
    }
}
