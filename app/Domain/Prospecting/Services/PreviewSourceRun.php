<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\Prospecting\Enums\SourceRunState;
use App\Domain\Prospecting\Exceptions\SourceRunConflict;
use App\Models\SourceRun;
use Illuminate\Support\Facades\DB;

final class PreviewSourceRun
{
    /** @param array<string,mixed>|null $checkpoint */
    public function handle(SourceRun $run, ?array $checkpoint = null): SourceRun
    {
        return DB::transaction(function () use ($run, $checkpoint): SourceRun {
            $locked = SourceRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $state = SourceRunState::from((string) $locked->getRawOriginal('state'));
            if (! in_array($state, [SourceRunState::Draft, SourceRunState::Previewed], true)) {
                throw new SourceRunConflict('Only a draft source run can be previewed.');
            }
            $locked->forceFill([
                'state' => SourceRunState::Previewed,
                'checkpoint' => $checkpoint ?? $locked->checkpoint,
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    /** @param array<string, mixed>|null $checkpoint */
    public function preview(SourceRun $run, ?array $checkpoint = null): SourceRun
    {
        return $this->handle($run, $checkpoint);
    }
}
