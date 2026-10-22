<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Enums\FulfilmentMode;
use App\Models\FulfilmentCapability;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateFulfilmentCapability
{
    /** @param array<string, mixed> $input */
    public function handle(Workspace $workspace, User $actor, array $input, ?string $operationId = null): FulfilmentCapability
    {
        if (! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can configure fulfilment.');
        }
        $name = trim((string) ($input['display_name'] ?? ''));
        $mode = FulfilmentMode::tryFrom((string) ($input['mode'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160 || $mode === null) {
            throw ValidationException::withMessages(['fulfilment' => 'Provide a name and supported fulfilment mode.']);
        }

        $ownerId = isset($input['owner_user_id']) ? (int) $input['owner_user_id'] : null;
        if ($ownerId !== null && ! $workspace->memberships()->where('user_id', $ownerId)->exists()) {
            throw ValidationException::withMessages(['owner_user_id' => 'The fulfilment owner must belong to this workspace.']);
        }
        $operationId = $operationId === null ? null : trim($operationId);
        if ($operationId !== null && ($operationId === '' || strlen($operationId) > 160)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Provide an operation key of at most 160 characters.']);
        }
        $operationHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($workspace, $input, $name, $mode, $ownerId, $operationId, $operationHash): FulfilmentCapability {
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if ($operationId !== null) {
                $existing = FulfilmentCapability::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('client_operation_id', $operationId)
                    ->first();
                if ($existing !== null) {
                    if (! hash_equals((string) $existing->operation_hash, $operationHash)) {
                        throw new \RuntimeException('operation_replay_mismatch');
                    }

                    return $existing;
                }
            }

            return FulfilmentCapability::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'display_name' => $name,
                'mode' => $mode,
                'status' => ($input['status'] ?? null) === 'active' ? 'active' : 'draft',
                'configuration_version' => 1,
                'client_operation_id' => $operationId,
                'operation_hash' => $operationHash,
                'configuration' => is_array($input['configuration'] ?? null) ? $input['configuration'] : [],
                'owner_user_id' => $ownerId,
                'response_sla_hours' => isset($input['response_sla_hours']) ? max(1, (int) $input['response_sla_hours']) : null,
                'capacity_limit' => isset($input['capacity_limit']) ? max(1, (int) $input['capacity_limit']) : null,
                'capacity_window' => in_array($input['capacity_window'] ?? null, ['day', 'week', 'month'], true)
                    ? $input['capacity_window'] : null,
                'health_state' => ($input['health_state'] ?? null) === 'healthy' ? 'healthy' : 'unknown',
                'last_checked_at' => ($input['health_state'] ?? null) === 'healthy' ? now() : null,
            ]);
        }, 3);
    }
}
