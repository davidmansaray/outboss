<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Objectives\Enums\AttributionAssessmentState;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Models\AttributionAssessment;
use App\Models\ConversionEvent;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\Exposure;
use App\Models\GtmConversation;
use App\Models\Objective;
use App\Models\ObjectiveVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** Outcome read model containing only bounded, non-content fields safe for REST and Inertia. */
final class ObjectiveOutcomeSummary
{
    private const LINEAGE_LIMIT = 25;

    /** @return array<string, array<string, int>> */
    public function forPlan(ExecutionPlan $plan): array
    {
        $scoped = ExecutionPlan::query()
            ->where('workspace_id', $plan->workspace_id)
            ->whereKey($plan->id)
            ->firstOrFail();
        $version = ObjectiveVersion::query()
            ->where('workspace_id', $scoped->workspace_id)
            ->whereKey($scoped->objective_version_id)
            ->firstOrFail();

        return $this->summarize($version, (string) $scoped->id);
    }

    /** @return array<string, array<string, int>> */
    public function forObjective(Objective $objective): array
    {
        $scoped = Objective::query()
            ->where('workspace_id', $objective->workspace_id)
            ->whereKey($objective->id)
            ->firstOrFail();
        $version = ObjectiveVersion::query()
            ->where('workspace_id', $scoped->workspace_id)
            ->whereKey($scoped->current_version_id)
            ->firstOrFail();

        return $this->summarize($version);
    }

    /**
     * @return array{
     *     exposures:list<array{id:string,action_id:string,send_attempt_id:int,message_id:int,thread_id:int,source_event_id:string,boundary:string,occurred_at:?string}>,
     *     conversations:list<array{id:string,action_id:string,exposure_id:string,thread_id:int,state:string,latest_event_at:?string}>,
     *     conversions:list<array{event_id:string,assessment_id:?string,assessment_state:string,selected_exposure_id:?string,method:?string,reason_codes:list<string>,assessed_at:?string}>
     * }
     */
    public function forPlanLineage(ExecutionPlan $plan): array
    {
        $scoped = ExecutionPlan::query()
            ->where('workspace_id', $plan->workspace_id)
            ->whereKey($plan->id)
            ->firstOrFail();
        $version = ObjectiveVersion::query()
            ->where('workspace_id', $scoped->workspace_id)
            ->whereKey($scoped->objective_version_id)
            ->firstOrFail();

        $exposures = Exposure::query()
            ->where('workspace_id', $scoped->workspace_id)
            ->where('objective_version_id', $version->id)
            ->whereHas('action', fn (Builder $action) => $action->where('execution_plan_id', $scoped->id))
            ->latest('occurred_at')
            ->latest('id')
            ->limit(self::LINEAGE_LIMIT)
            ->get();
        $conversations = GtmConversation::query()
            ->where('workspace_id', $scoped->workspace_id)
            ->where('objective_version_id', $version->id)
            ->whereHas('action', fn (Builder $action) => $action->where('execution_plan_id', $scoped->id))
            ->withMax('events as latest_event_at', 'occurred_at')
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::LINEAGE_LIMIT)
            ->get();
        $conversionHeads = $this->conversionHeadsQuery($version)
            ->latest('occurred_at')
            ->latest('id')
            ->limit(self::LINEAGE_LIMIT)
            ->get();
        $latestAssessments = $this->latestAssessments($version, $conversionHeads);

        return [
            'exposures' => array_values($exposures->map(fn (Exposure $exposure): array => [
                'id' => (string) $exposure->id,
                'action_id' => (string) $exposure->execution_plan_action_id,
                'send_attempt_id' => (int) $exposure->send_attempt_id,
                'message_id' => (int) $exposure->message_id,
                'thread_id' => (int) $exposure->thread_id,
                'source_event_id' => (string) $exposure->source_event_id,
                'boundary' => (string) $exposure->getRawOriginal('boundary'),
                'occurred_at' => $this->responseTimestamp($exposure->getAttribute('occurred_at')),
            ])->all()),
            'conversations' => array_values($conversations->map(fn (GtmConversation $conversation): array => [
                'id' => (string) $conversation->id,
                'action_id' => (string) $conversation->execution_plan_action_id,
                'exposure_id' => (string) $conversation->exposure_id,
                'thread_id' => (int) $conversation->thread_id,
                'state' => (string) $conversation->getRawOriginal('state'),
                'latest_event_at' => $this->responseTimestamp($conversation->getAttribute('latest_event_at')),
            ])->all()),
            'conversions' => array_values($conversionHeads->map(function (ConversionEvent $event) use ($latestAssessments): array {
                $assessment = $latestAssessments->get($event->id);

                return [
                    'event_id' => (string) $event->id,
                    'assessment_id' => $assessment instanceof AttributionAssessment ? (string) $assessment->id : null,
                    'assessment_state' => $assessment instanceof AttributionAssessment
                        ? (string) $assessment->getRawOriginal('state')
                        : 'pending',
                    'selected_exposure_id' => $assessment instanceof AttributionAssessment && $assessment->selected_exposure_id !== null
                        ? (string) $assessment->selected_exposure_id
                        : null,
                    'method' => $assessment instanceof AttributionAssessment && $assessment->getRawOriginal('method') !== null
                        ? (string) $assessment->getRawOriginal('method')
                        : null,
                    'reason_codes' => $assessment instanceof AttributionAssessment
                        ? $this->safeReasonCodes($assessment->reason_codes)
                        : [],
                    'assessed_at' => $assessment instanceof AttributionAssessment
                        ? $this->responseTimestamp($assessment->getAttribute('assessed_at'))
                        : null,
                ];
            })->all()),
        ];
    }

    /** @return array<string, array<string, int>> */
    private function summarize(ObjectiveVersion $version, ?string $planId = null): array
    {
        $exposures = Exposure::query()
            ->where('workspace_id', $version->workspace_id)
            ->where('objective_version_id', $version->id)
            ->when($planId !== null, fn (Builder $query) => $query->whereHas(
                'action',
                fn (Builder $action) => $action->where('execution_plan_id', $planId),
            ));
        $actionScope = fn (Builder $query): Builder => $query
            ->where('workspace_id', $version->workspace_id)
            ->whereHas('executionPlan', fn (Builder $plan) => $plan
                ->where('objective_version_id', $version->id)
                ->when($planId !== null, fn (Builder $selected) => $selected->whereKey($planId)));
        $conversations = GtmConversation::query()
            ->where('workspace_id', $version->workspace_id)
            ->where('objective_version_id', $version->id)
            ->when($planId !== null, fn (Builder $query) => $query->whereHas(
                'action',
                fn (Builder $action) => $action->where('execution_plan_id', $planId),
            ));
        $conversionHeads = $this->conversionHeads($version);
        $latestAssessments = $this->latestAssessments($version, $conversionHeads);

        $attributed = $latestAssessments->filter(
            fn (AttributionAssessment $assessment): bool => $assessment->getRawOriginal('state') === AttributionAssessmentState::Attributed->value
                || ($assessment->getRawOriginal('state') === AttributionAssessmentState::ManuallyOverridden->value
                    && $assessment->selected_exposure_id !== null),
        )->count();
        $ambiguous = $latestAssessments->filter(
            fn (AttributionAssessment $assessment): bool => $assessment->getRawOriginal('state') === AttributionAssessmentState::Ambiguous->value,
        )->count();
        $rejected = $latestAssessments->filter(
            fn (AttributionAssessment $assessment): bool => in_array($assessment->getRawOriginal('state'), [
                AttributionAssessmentState::Unattributed->value,
                AttributionAssessmentState::Ineligible->value,
                AttributionAssessmentState::Reversed->value,
            ], true) || ($assessment->getRawOriginal('state') === AttributionAssessmentState::ManuallyOverridden->value
                && $assessment->selected_exposure_id === null),
        )->count();

        return [
            'exposures' => [
                'total' => (clone $exposures)->count(),
                'provider_accepted' => (clone $exposures)->whereHas(
                    'sendAttempt',
                    fn (Builder $attempt) => $attempt->where('status', SendAttemptStatus::ProviderAccepted->value),
                )->count(),
                'confirmed_sent' => (clone $exposures)->whereHas(
                    'sendAttempt',
                    fn (Builder $attempt) => $attempt->where('status', SendAttemptStatus::Sent->value),
                )->count(),
                'uncertain' => $actionScope(ExecutionPlanAction::query())
                    ->where('state', ExecutionPlanActionState::Uncertain->value)
                    ->count(),
            ],
            'conversations' => [
                'total' => (clone $conversations)->count(),
                'replied' => (clone $conversations)->whereHas(
                    'events',
                    fn (Builder $event) => $event->where('event', 'reply_observed'),
                )->count(),
                'stopped' => (clone $conversations)->whereHas(
                    'events',
                    fn (Builder $event) => $event->where('event', 'stopped'),
                )->count(),
            ],
            'conversions' => [
                'total' => $conversionHeads->count(),
            ],
            'attributions' => [
                'attributed' => $attributed,
                'ambiguous' => $ambiguous,
                'rejected' => $rejected,
                'pending' => max(0, $conversionHeads->count() - $latestAssessments->count()),
            ],
        ];
    }

    /** @return Collection<int, ConversionEvent> */
    private function conversionHeads(ObjectiveVersion $version): Collection
    {
        return $this->conversionHeadsQuery($version)->get();
    }

    /** @return Builder<ConversionEvent> */
    private function conversionHeadsQuery(ObjectiveVersion $version): Builder
    {
        return ConversionEvent::query()
            ->where('workspace_id', $version->workspace_id)
            ->where('conversion_definition_version_id', $version->conversion_definition_version_id)
            ->whereBetween('occurred_at', [$version->starts_at, $version->ends_at])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('conversion_events as successors')
                    ->whereColumn('successors.workspace_id', 'conversion_events.workspace_id')
                    ->where(function ($lineage): void {
                        $lineage->whereColumn('successors.corrects_event_id', 'conversion_events.id')
                            ->orWhereColumn('successors.reverses_event_id', 'conversion_events.id');
                    });
            });
    }

    /**
     * @param  Collection<int, ConversionEvent>  $events
     * @return Collection<string, AttributionAssessment>
     */
    private function latestAssessments(ObjectiveVersion $version, Collection $events): Collection
    {
        $assessments = AttributionAssessment::query()
            ->where('workspace_id', $version->workspace_id)
            ->where('objective_version_id', $version->id)
            ->whereIn('conversion_event_id', $events->pluck('id'))
            ->orderBy('assessed_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $supersededIds = $assessments
            ->pluck('prior_assessment_id')
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $assessments
            ->reject(fn (AttributionAssessment $assessment): bool => in_array((string) $assessment->id, $supersededIds, true))
            ->keyBy('conversion_event_id');
    }

    private function responseTimestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->utc()->toISOString();
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->utc()->toISOString();
    }

    /** @return list<string> */
    private function safeReasonCodes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(collect($value)
            ->filter(fn (mixed $reason): bool => is_string($reason) && trim($reason) !== '')
            ->map(fn (string $reason): string => str_starts_with($reason, 'owner_reason:')
                ? 'owner_override_reason_recorded'
                : $reason)
            ->filter(fn (string $reason): bool => preg_match('/^[a-z0-9_]+$/', $reason) === 1)
            ->unique()
            ->all());
    }
}
