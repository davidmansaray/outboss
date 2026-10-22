<?php

namespace App\Domain\Conversions\Services;

use App\Models\ConversionDefinition;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InitializeMeetingMeasurement
{
    public function handle(Workspace $workspace, User $actor): void
    {
        abort_unless($workspace->isOwner($actor), 403);
        DB::transaction(function () use ($workspace, $actor): void {
            $workspace->newQuery()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if (ConversionDefinition::query()->where('workspace_id', $workspace->id)->where('event_key', 'meeting_booked')->exists()) {
                throw ValidationException::withMessages(['measurement' => 'A meeting measurement already exists. Manage its existing definition rather than replacing it.']);
            }
            $definition = app(CopyConversionDefinitionTemplates::class)->handle($workspace, $actor, ['meeting_booked'])->firstOrFail();
            app(ActivateConversionDefinition::class)->handle($definition, $actor, $definition->lock_version);
        });
    }
}
