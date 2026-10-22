<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Conversions\Enums\ConversionEventState;
use App\Models\AttributionAssessment;
use App\Models\ConversionEvent;

/** Bounded replay for current Conversion heads missing an applicable assessment. */
final class ReconcileObjectiveAttribution
{
    public function __construct(private readonly AssessObjectiveAttribution $assess) {}

    public function handle(?int $workspaceId = null, int $limit = 100): int
    {
        $events = ConversionEvent::query()
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('conversion_events as successors')
                    ->whereColumn('successors.workspace_id', 'conversion_events.workspace_id')
                    ->where(function ($lineage): void {
                        $lineage->whereColumn('successors.corrects_event_id', 'conversion_events.id')
                            ->orWhereColumn('successors.reverses_event_id', 'conversion_events.id');
                    });
            })
            ->where(function ($repair): void {
                $repair->whereExists(function ($versions): void {
                    $versions->selectRaw('1')
                        ->from('objective_versions')
                        ->whereColumn('objective_versions.workspace_id', 'conversion_events.workspace_id')
                        ->whereColumn('objective_versions.conversion_definition_version_id', 'conversion_events.conversion_definition_version_id')
                        ->whereColumn('objective_versions.starts_at', '<=', 'conversion_events.occurred_at')
                        ->whereColumn('objective_versions.ends_at', '>=', 'conversion_events.occurred_at')
                        ->whereNotExists(function ($assessments): void {
                            $assessments->selectRaw('1')
                                ->from('attribution_assessments')
                                ->whereColumn('attribution_assessments.workspace_id', 'conversion_events.workspace_id')
                                ->whereColumn('attribution_assessments.conversion_event_id', 'conversion_events.id')
                                ->whereColumn('attribution_assessments.objective_version_id', 'objective_versions.id');
                        });
                })->orWhere(function ($reversals): void {
                    $reversals->where('state', ConversionEventState::Reversed->value)
                        ->whereNotNull('reverses_event_id')
                        ->whereNotExists(function ($assessments): void {
                            $assessments->selectRaw('1')
                                ->from('attribution_assessments')
                                ->whereColumn('attribution_assessments.workspace_id', 'conversion_events.workspace_id')
                                ->whereColumn('attribution_assessments.conversion_event_id', 'conversion_events.id');
                        });
                });
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->get(['id', 'workspace_id']);

        $repaired = 0;
        foreach ($events as $event) {
            $before = AttributionAssessment::query()
                ->where('workspace_id', $event->getAttribute('workspace_id'))
                ->where('conversion_event_id', $event->id)
                ->count();
            $this->assess->handle((int) $event->getAttribute('workspace_id'), (string) $event->id);
            $after = AttributionAssessment::query()
                ->where('workspace_id', $event->getAttribute('workspace_id'))
                ->where('conversion_event_id', $event->id)
                ->count();
            $repaired += max(0, $after - $before);
        }

        return $repaired;
    }
}
