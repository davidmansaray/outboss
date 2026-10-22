<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\StrategyStatus;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\Strategy;
use App\Models\StrategyVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class VersionStrategy
{
    public function __construct(private readonly CreateStrategy $validator) {}

    /** @param array<string, mixed> $input */
    public function handle(Strategy $strategy, User $actor, array $input, int $expectedLockVersion): StrategyVersion
    {
        return DB::transaction(function () use ($strategy, $actor, $input, $expectedLockVersion): StrategyVersion {
            $locked = Strategy::query()->where('workspace_id', $strategy->workspace_id)
                ->whereKey($strategy->id)->lockForUpdate()->firstOrFail();
            $workspace = Workspace::query()->whereKey($locked->workspace_id)->firstOrFail();
            if (! $workspace->isOwner($actor)) {
                throw new AuthorizationException('Only the workspace owner can version Strategies.');
            }
            if ((int) $locked->lock_version !== $expectedLockVersion) {
                throw new RuntimeException('stale_version');
            }
            if ($locked->status !== StrategyStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Only draft Strategies can be versioned.']);
            }
            $payload = $this->validator->validatedPayload((int) $locked->workspace_id, $input);
            $hash = CanonicalObjectivePayload::hash($payload);
            $current = $locked->currentVersion()->first();
            if ($current !== null && hash_equals((string) $current->content_hash, $hash)) {
                return $current;
            }
            $version = StrategyVersion::query()->create([
                'workspace_id' => $locked->workspace_id,
                'strategy_id' => $locked->id,
                'version' => ((int) $locked->versions()->max('version')) + 1,
                ...$payload,
                'content_hash' => $hash,
                'created_by_user_id' => $actor->id,
            ]);
            $updated = Strategy::query()->whereKey($locked->id)->where('lock_version', $expectedLockVersion)->update([
                'current_version_id' => $version->id,
                'updated_by_user_id' => $actor->id,
                'lock_version' => $expectedLockVersion + 1,
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new RuntimeException('stale_version');
            }

            return $version;
        }, 3);
    }
}
