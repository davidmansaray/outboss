<?php

namespace App\Domain\Objectives\Services;

use App\Models\ConversionDefinitionVersion;
use Illuminate\Validation\ValidationException;

final class ValidateGoalMeasurement
{
    public function handle(int $workspaceId, string $measurement, string $versionId): string
    {
        $definition = ConversionDefinitionVersion::query()->where('workspace_id', $workspaceId)
            ->whereKey($versionId)
            ->whereHas('definition', fn ($query) => $query->where('workspace_id', $workspaceId)
                ->where('event_key', $measurement)->where('status', 'active')
                ->whereColumn('current_version_id', 'conversion_definition_versions.id'))->first();
        if ($definition === null || $definition->subject_type !== 'Account') {
            throw ValidationException::withMessages(['conversion_definition_version_id' => 'Open the Objective and save its outcome definition under What counts. Then select the updated Objective version in campaign setup before preparing review.']);
        }

        return $definition->id;
    }
}
