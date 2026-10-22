<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\Prospecting\Enums\SourceRunState;
use App\Domain\Prospecting\Exceptions\SourceRunConflict;
use App\Models\SourceRun;
use Illuminate\Support\Facades\DB;

final class CommitSourceRun
{
    public function __construct(private readonly ReconcileSourceRunCounts $reconcile) {}

    public function handle(SourceRun $run, bool $complete = true): SourceRun
    {
        return DB::transaction(function () use ($run, $complete): SourceRun {
            $locked = SourceRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $state = SourceRunState::from((string) $locked->getRawOriginal('state'));
            if ($state->isTerminal()) {
                return $locked;
            }
            if (! $state->canAcceptObservations()) {
                throw new SourceRunConflict('The source run cannot be committed in its current state.');
            }
            $locked->forceFill([
                'state' => $complete ? SourceRunState::Completed : SourceRunState::Partial,
                'started_at' => $locked->started_at ?? now(),
                'finished_at' => $complete ? now() : null,
                'lease_owner' => null,
                'lease_expires_at' => null,
            ])->save();

            return $this->reconcile->handle($locked);
        }, 3);
    }

    public function commit(SourceRun $run, bool $complete = true): SourceRun
    {
        return $this->handle($run, $complete);
    }
}
