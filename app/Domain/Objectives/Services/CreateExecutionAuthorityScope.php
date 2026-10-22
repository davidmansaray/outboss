<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\ExecutionAuthorityScope;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CreateExecutionAuthorityScope
{
    /** @param array<string,mixed> $input */
    public function handle(Workspace $workspace, User $actor, array $input): ExecutionAuthorityScope
    {
        if ((int) $actor->currentWorkspace()->id !== (int) $workspace->id || ! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the current workspace owner can authorize execution limits.');
        }
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:160'], 'operation_id' => ['required', 'uuid'],
            'authority' => ['required', 'array'],
        ])->validate();
        $name = trim($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Name this execution allowance.']);
        }
        $authority = app(ValidateExecutionAuthority::class)->handle($data['authority']);
        $hash = CanonicalObjectivePayload::hash(['name' => $name, 'authority' => $authority]);

        return DB::transaction(function () use ($workspace, $actor, $data, $name, $authority, $hash): ExecutionAuthorityScope {
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $existing = ExecutionAuthorityScope::query()->where('workspace_id', $workspace->id)
                ->where('operation_id', $data['operation_id'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->content_hash, $hash)) {
                    throw ValidationException::withMessages(['operation_id' => 'This request already identifies a different execution allowance.']);
                }

                return $existing; // Read replay is safe even after the window expires.
            }
            if (! Carbon::parse($authority['ends_at'])->isFuture()) {
                throw ValidationException::withMessages(['authority.ends_at' => 'A new execution allowance must end in the future.']);
            }

            return ExecutionAuthorityScope::query()->create([
                'workspace_id' => $workspace->id, 'name' => $name, 'authority' => $authority,
                'content_hash' => $hash, 'operation_id' => $data['operation_id'], 'created_by_user_id' => $actor->id,
            ]);
        }, 3);
    }
}
