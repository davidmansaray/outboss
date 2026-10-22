<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\ObjectiveStatus;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\Objective;
use App\Models\ObjectiveGoalVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveObjectiveGoal
{
    /** @param array<string, mixed> $input */
    public function handle(Workspace $workspace, User $actor, array $input, ?Objective $objective = null): Objective
    {
        if (! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can save Objectives.');
        }
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'measurement' => ['required', 'in:meeting_booked'],
            'target_count' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'deadline' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'creation_key' => [$objective === null ? 'required' : 'nullable', 'string', 'max:80'],
            'lock_version' => [$objective === null ? 'nullable' : 'required', 'integer', 'min:1'],
            'conversion_definition_version_id' => ['sometimes', 'nullable', 'uuid'],
            'execution_authority' => ['missing'],
        ])->validate();
        $payload = [
            'name' => trim($data['name']),
            'description' => isset($data['description']) ? trim($data['description']) : null,
            'measurement' => $data['measurement'],
            'target_count' => isset($data['target_count']) ? (int) $data['target_count'] : null,
            'deadline' => $data['deadline'] ?? null,
        ];
        if ($payload['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Enter an Objective name.']);
        }
        if (array_key_exists('conversion_definition_version_id', $data)) {
            $payload['conversion_definition_version_id'] = $data['conversion_definition_version_id'] === null ? null
                : app(ValidateGoalMeasurement::class)->handle((int) $workspace->id, $payload['measurement'], $data['conversion_definition_version_id']);
        }
        $hash = CanonicalObjectivePayload::hash($payload);

        return DB::transaction(function () use ($workspace, $actor, $objective, $data, $payload, $hash): Objective {
            // Serializes creation-key replays; versions additionally lock their aggregate.
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if ($objective === null) {
                $existing = ObjectiveGoalVersion::query()->where('workspace_id', $workspace->id)
                    ->where('creation_key', $data['creation_key'])->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->content_hash, $hash)) {
                        throw ValidationException::withMessages(['creation_key' => 'This save request was already used for different content.']);
                    }

                    return Objective::query()->where('workspace_id', $workspace->id)->findOrFail($existing->objective_id);
                }
                $locked = app(CreateObjective::class)->handle($workspace, $actor);
            } else {
                $locked = Objective::query()->where('workspace_id', $workspace->id)
                    ->whereKey($objective->id)->lockForUpdate()->firstOrFail();
                if ($locked->lock_version !== (int) $data['lock_version']) {
                    throw ValidationException::withMessages(['lock_version' => 'This Objective changed. Reload before saving.']);
                }
                // Human campaigns pin immutable goal versions. A new library version
                // must not rewrite those pins; legacy AI amendments stay separate.
                if ($locked->status !== ObjectiveStatus::Draft || $locked->current_version_id !== null || $locked->runs()->where('origin', '!=', 'human')->exists()) {
                    throw ValidationException::withMessages(['objective' => 'An Objective with execution history cannot be edited through the draft editor.']);
                }
            }
            $current = ObjectiveGoalVersion::query()->where('workspace_id', $workspace->id)
                ->where('objective_id', $locked->id)->orderByDesc('version')->first();
            // Preserve transitional historical values on ordinary evergreen edits.
            // The authority-scope migration, not a name edit, owns moving them.
            if ($current !== null) {
                foreach (['target_count', 'deadline'] as $field) {
                    if (! array_key_exists($field, $data)) {
                        $payload[$field] = $field === 'deadline' ? $current->deadline?->toDateString() : $current->target_count;
                    }
                }
                $hash = CanonicalObjectivePayload::hash($payload);
            }
            if ($current !== null) {
                // Historical authority is read-only. Copy it exactly, including expired
                // windows; only the explicit import operation maps it to a scope.
                if ($current->execution_authority !== null) {
                    $payload['execution_authority'] = $current->execution_authority;
                }
                if (! array_key_exists('conversion_definition_version_id', $payload) && $current->conversion_definition_version_id !== null) {
                    $payload['conversion_definition_version_id'] = $current->conversion_definition_version_id;
                }
                $hash = CanonicalObjectivePayload::hash($payload);
            }
            if ($current !== null && hash_equals($current->content_hash, $hash)) {
                return $locked;
            }
            $version = ObjectiveGoalVersion::query()->create([
                ...$payload,
                'workspace_id' => $workspace->id,
                'objective_id' => $locked->id,
                'version' => ($current->version ?? 0) + 1,
                'creation_key' => $objective === null ? $data['creation_key'] : null,
                'content_hash' => $hash,
                'created_by_user_id' => $actor->id,
            ]);
            $locked->forceFill(['current_goal_version_id' => $version->id, 'lock_version' => $locked->lock_version + 1, 'last_actor_user_id' => $actor->id])->save();

            return $locked;
        }, 3);
    }
}
