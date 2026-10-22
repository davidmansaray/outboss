<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Conversions\Enums\ConversionEventState;
use App\Domain\Objectives\Enums\AttributionAssessmentState;
use App\Domain\Objectives\Enums\ObjectiveTargetKind;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\AttributionAssessment;
use App\Models\ConversionEvent;
use App\Models\ObjectiveOutcomeProgress;
use App\Models\ObjectiveVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Rebuild the disposable Objective progress projection from append-only history. */
final class RebuildObjectiveProgress
{
    /** @return array<string, int|string|null> */
    public function handle(int $workspaceId, string $objectiveVersionId): array
    {
        $version = ObjectiveVersion::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($objectiveVersionId)
            ->firstOrFail();

        $assessments = AttributionAssessment::query()
            ->where('workspace_id', $workspaceId)
            ->where('objective_version_id', $objectiveVersionId)
            ->orderBy('assessed_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $latestByEvent = [];
        $supersededAssessmentIds = $assessments
            ->pluck('prior_assessment_id')
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        foreach ($assessments as $assessment) {
            if (in_array((string) $assessment->id, $supersededAssessmentIds, true)) {
                continue;
            }
            $latestByEvent[(string) $assessment->conversion_event_id] = $assessment;
        }
        $eventModels = ConversionEvent::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', array_keys($latestByEvent))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('conversion_events as successors')
                    ->whereColumn('successors.workspace_id', 'conversion_events.workspace_id')
                    ->where(function ($lineage): void {
                        $lineage->whereColumn('successors.corrects_event_id', 'conversion_events.id')
                            ->orWhereColumn('successors.reverses_event_id', 'conversion_events.id');
                    });
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $events = [];
        foreach ($eventModels as $event) {
            $events[(string) $event->id] = $event;
        }

        $countedByLineage = [];
        foreach ($latestByEvent as $eventId => $assessment) {
            $event = $events[$eventId] ?? null;
            if (! $event instanceof ConversionEvent
                || $event->getRawOriginal('state') !== ConversionEventState::Matched->value
                || $assessment->selected_exposure_id === null
                || ! in_array($assessment->getRawOriginal('state'), [
                    AttributionAssessmentState::Attributed->value,
                    AttributionAssessmentState::ManuallyOverridden->value,
                ], true)) {
                continue;
            }
            $lineageKey = (string) ($event->lineage_root_id ?: $event->id);
            $countedByLineage[$lineageKey] = [$assessment, $event];
        }

        $attributedCount = count($countedByLineage);
        $attributedValue = '0.000000';
        $value = 0.0;
        $inputs = [];
        foreach ($countedByLineage as $lineageKey => [$assessment, $event]) {
            if ($event->value !== null
                && ($version->currency === null || (string) $event->currency === (string) $version->currency)) {
                $value += (float) $event->value;
            }
            $inputs[] = [
                'lineage' => $lineageKey,
                'assessment_id' => (string) $assessment->id,
                'assessment_hash' => (string) $assessment->content_hash,
                'event_id' => (string) $event->id,
                'value' => $event->value === null ? null : (string) $event->value,
                'currency' => $event->currency,
            ];
        }
        $attributedValue = number_format($value, 6, '.', '');

        $targetKind = ObjectiveTargetKind::from((string) $version->getRawOriginal('target_kind'));
        $target = $targetKind === ObjectiveTargetKind::Count
            ? (float) ($version->target_count ?? 0)
            : (float) ($version->target_value ?? 0);
        $achieved = $targetKind === ObjectiveTargetKind::Count ? $attributedCount : $value;
        $completion = $target <= 0 ? 0.0 : min(100.0, ($achieved / $target) * 100);
        $inputHash = CanonicalObjectivePayload::hash([
            'version' => 'objective_progress_v1',
            'objective_version_id' => (string) $version->id,
            'inputs' => $inputs,
        ]);

        $progress = DB::transaction(function () use (
            $workspaceId,
            $version,
            $attributedCount,
            $attributedValue,
            $completion,
            $inputHash,
        ): ObjectiveOutcomeProgress {
            ObjectiveVersion::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($version->id)
                ->lockForUpdate()
                ->firstOrFail();

            return ObjectiveOutcomeProgress::query()->updateOrCreate([
                'workspace_id' => $workspaceId,
                'objective_version_id' => $version->id,
            ], [
                'id' => (string) Str::uuid(),
                'objective_id' => $version->objective_id,
                'target_kind' => $version->target_kind,
                'target_count' => $version->target_count,
                'target_value' => $version->target_value,
                'currency' => $version->currency,
                'attributed_count' => $attributedCount,
                'attributed_value' => $attributedValue,
                'completion_percent' => number_format($completion, 4, '.', ''),
                'input_hash' => $inputHash,
                'rebuilt_at' => now(),
            ]);
        }, 3);

        return [
            'objective_id' => (string) $progress->objective_id,
            'objective_version_id' => (string) $progress->objective_version_id,
            'target_kind' => (string) $progress->getRawOriginal('target_kind'),
            'target_count' => $progress->target_count,
            'target_value' => $progress->target_value === null ? null : (string) $progress->target_value,
            'currency' => $progress->currency,
            'attributed_count' => (int) $progress->attributed_count,
            'attributed_value' => (string) $progress->attributed_value,
            'completion_percent' => (string) $progress->completion_percent,
        ];
    }
}
