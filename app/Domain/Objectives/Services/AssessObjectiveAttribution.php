<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Conversions\Enums\ConversionEventState;
use App\Domain\Conversions\Enums\ConversionSourceType;
use App\Domain\Conversions\Enums\ConversionVerificationState;
use App\Domain\Objectives\Enums\AttributionAssessmentState;
use App\Domain\Objectives\Enums\AttributionMethod;
use App\Domain\Objectives\Enums\ObjectiveTargetKind;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Jobs\Objectives\RebuildObjectiveProgress;
use App\Models\AttributionAssessment;
use App\Models\AttributionAssessmentCandidate;
use App\Models\ConversionDefinitionVersion;
use App\Models\ConversionEvent;
use App\Models\ExecutionPlanAction;
use App\Models\Exposure;
use App\Models\ObjectiveVersion;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Conservative, exact-match Objective attribution rules for the S3 pilot. */
final class AssessObjectiveAttribution
{
    public const RULES_VERSION = 'objective_attribution_v1';

    /** @return list<AttributionAssessment> */
    public function handle(int $workspaceId, string $conversionEventId): array
    {
        $event = ConversionEvent::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($conversionEventId)
            ->first();
        if (! $event instanceof ConversionEvent) {
            return [];
        }

        if ($event->getRawOriginal('state') === ConversionEventState::Reversed->value
            && $event->reverses_event_id !== null) {
            return $this->assessReversal($event);
        }

        $versions = ObjectiveVersion::query()
            ->where('workspace_id', $workspaceId)
            ->where('conversion_definition_version_id', $event->conversion_definition_version_id)
            ->where('starts_at', '<=', $event->occurred_at)
            ->where('ends_at', '>=', $event->occurred_at)
            ->orderBy('objective_id')
            ->orderBy('version')
            ->get();

        $assessments = [];
        foreach ($versions as $version) {
            $assessment = $this->assessVersion($event, $version);
            $assessments[] = $assessment;
            $this->scheduleProgress((int) $version->workspace_id, (string) $version->id);
        }

        return $assessments;
    }

    private function assessVersion(ConversionEvent $event, ObjectiveVersion $version): AttributionAssessment
    {
        $windowEndedAt = $this->timestamp($event->getAttribute('occurred_at'));
        $windowStartedAt = $windowEndedAt->subDays((int) $version->attribution_window_days);
        $correlation = $this->resolveExplicitCorrelation($event, $version);
        $candidates = $this->candidateExposures($event, $version, $correlation['exposure']);
        $globalReasons = $this->globalIneligibilityReasons($event, $version);
        $candidateRows = [];
        $eligible = [];

        foreach ($candidates as $exposure) {
            $reasons = $this->candidateReasons($event, $version, $exposure, $windowStartedAt, $windowEndedAt);
            $isExplicit = $correlation['exposure'] instanceof Exposure
                && (string) $correlation['exposure']->id === (string) $exposure->id;
            $row = [
                'exposure' => $exposure,
                'eligible' => $reasons === [],
                'reason_codes' => $reasons === [] ? ['eligible_exact_match'] : $reasons,
                'time_distance_seconds' => (int) max(0, $this->timestamp($exposure->getAttribute('occurred_at'))
                    ->diffInSeconds($windowEndedAt, true)),
                'explicit_correlation' => $isExplicit,
            ];
            $candidateRows[] = $row;
            if ($row['eligible']) {
                $eligible[] = $exposure;
            }
        }

        $selected = null;
        $method = null;
        $reasonCodes = $globalReasons;
        if ($correlation['present']) {
            $method = AttributionMethod::ExplicitCorrelation;
            if (! $correlation['valid']) {
                $state = AttributionAssessmentState::Ineligible;
                $reasonCodes = array_values(array_unique([...$reasonCodes, ...$correlation['reason_codes']]));
            } elseif (! $correlation['exposure'] instanceof Exposure
                || ! $this->isEligibleCorrelation($candidateRows, $correlation['exposure'])) {
                $state = AttributionAssessmentState::Ineligible;
                $reasonCodes[] = 'explicit_correlation_ineligible';
            } elseif ($globalReasons !== []) {
                $state = AttributionAssessmentState::Ineligible;
            } else {
                $state = AttributionAssessmentState::Attributed;
                $selected = $correlation['exposure'];
                $reasonCodes = ['explicit_correlation_valid'];
            }
        } elseif ($globalReasons !== []) {
            $state = AttributionAssessmentState::Ineligible;
        } elseif (count($eligible) === 1) {
            $state = AttributionAssessmentState::Attributed;
            $method = AttributionMethod::SingleEligibleTouch;
            $selected = $eligible[0];
            $reasonCodes = ['single_eligible_touch'];
        } elseif (count($eligible) > 1) {
            $state = AttributionAssessmentState::Ambiguous;
            $reasonCodes = ['multiple_eligible_exposures'];
        } else {
            $state = AttributionAssessmentState::Unattributed;
            $reasonCodes = ['no_eligible_exposure'];
        }
        $reasonCodes = array_values(array_unique($reasonCodes));

        $prior = $event->corrects_event_id === null
            ? null
            : $this->latestAssessmentsForEvent(
                (int) $event->workspace_id,
                (string) $event->corrects_event_id,
            )->get((string) $version->id);
        $candidatePayload = array_map(static fn (array $row): array => [
            'exposure_id' => (string) $row['exposure']->id,
            'eligible' => $row['eligible'],
            'reason_codes' => $row['reason_codes'],
            'time_distance_seconds' => $row['time_distance_seconds'],
            'explicit_correlation' => $row['explicit_correlation'],
        ], $candidateRows);
        $candidateHash = CanonicalObjectivePayload::hash($candidatePayload);
        $inputHash = CanonicalObjectivePayload::hash([
            'version' => self::RULES_VERSION,
            'event_id' => (string) $event->id,
            'event_state' => (string) $event->getRawOriginal('state'),
            'event_account_id' => $event->matched_account_id,
            'definition_version_id' => (string) $event->conversion_definition_version_id,
            'occurred_at' => $event->occurred_at,
            'value' => $event->value === null ? null : (string) $event->value,
            'currency' => $event->currency,
            'properties' => $event->properties,
            'objective_version_id' => (string) $version->id,
            'candidate_hash' => $candidateHash,
        ]);
        $contentHash = CanonicalObjectivePayload::hash([
            'input_hash' => $inputHash,
            'state' => $state,
            'method' => $method,
            'selected_exposure_id' => $selected?->id,
            'prior_assessment_id' => $prior?->id,
            'reason_codes' => $reasonCodes,
        ]);
        $idempotencyFingerprint = CanonicalObjectivePayload::hash([
            'event_id' => (string) $event->id,
            'objective_version_id' => (string) $version->id,
            'rules_version' => self::RULES_VERSION,
            'content_hash' => $contentHash,
        ]);

        return DB::transaction(function () use (
            $event,
            $version,
            $state,
            $method,
            $selected,
            $prior,
            $windowStartedAt,
            $windowEndedAt,
            $candidateRows,
            $inputHash,
            $candidateHash,
            $contentHash,
            $reasonCodes,
            $idempotencyFingerprint,
        ): AttributionAssessment {
            ObjectiveVersion::query()
                ->where('workspace_id', $version->workspace_id)
                ->whereKey($version->id)
                ->lockForUpdate()
                ->firstOrFail();
            $existing = AttributionAssessment::query()
                ->where('workspace_id', $version->workspace_id)
                ->where('idempotency_fingerprint', $idempotencyFingerprint)
                ->first();
            if ($existing instanceof AttributionAssessment) {
                return $existing;
            }

            $assessment = AttributionAssessment::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $version->workspace_id,
                'conversion_event_id' => $event->id,
                'objective_id' => $version->objective_id,
                'objective_version_id' => $version->id,
                'conversion_definition_version_id' => $event->conversion_definition_version_id,
                'selected_exposure_id' => $selected?->id,
                'prior_assessment_id' => $prior?->id,
                'state' => $state,
                'method' => $method,
                'window_started_at' => $windowStartedAt,
                'window_ended_at' => $windowEndedAt,
                'candidate_count' => count($candidateRows),
                'rules_version' => self::RULES_VERSION,
                'input_hash' => $inputHash,
                'candidate_hash' => $candidateHash,
                'content_hash' => $contentHash,
                'reason_codes' => $reasonCodes,
                'actor_type' => 'system',
                'actor_user_id' => null,
                'idempotency_fingerprint' => $idempotencyFingerprint,
                'assessed_at' => now(),
                'created_at' => now(),
            ]);
            foreach ($candidateRows as $row) {
                AttributionAssessmentCandidate::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $version->workspace_id,
                    'attribution_assessment_id' => $assessment->id,
                    'exposure_id' => $row['exposure']->id,
                    'eligible' => $row['eligible'],
                    'reason_codes' => $row['reason_codes'],
                    'time_distance_seconds' => $row['time_distance_seconds'],
                    'explicit_correlation' => $row['explicit_correlation'],
                    'created_at' => now(),
                ]);
            }

            return $assessment->load('candidates');
        }, 3);
    }

    /** @return list<AttributionAssessment> */
    private function assessReversal(ConversionEvent $event): array
    {
        $latest = $this->latestAssessmentsForEvent(
            (int) $event->workspace_id,
            (string) $event->reverses_event_id,
        );
        $result = [];

        foreach ($latest as $prior) {
            $candidatePayload = [];
            foreach ($prior->candidates()->orderBy('exposure_id')->get() as $candidate) {
                $candidatePayload[] = [
                    'exposure_id' => (string) $candidate->exposure_id,
                    'eligible' => (bool) $candidate->eligible,
                    'reason_codes' => $candidate->reason_codes,
                    'time_distance_seconds' => (int) $candidate->time_distance_seconds,
                    'explicit_correlation' => (bool) $candidate->explicit_correlation,
                ];
            }
            $contentHash = CanonicalObjectivePayload::hash([
                'version' => self::RULES_VERSION,
                'reversal_event_id' => (string) $event->id,
                'prior_assessment_id' => (string) $prior->id,
                'candidate_payload' => $candidatePayload,
                'state' => AttributionAssessmentState::Reversed,
            ]);
            $fingerprint = CanonicalObjectivePayload::hash([
                'event_id' => (string) $event->id,
                'objective_version_id' => (string) $prior->objective_version_id,
                'rules_version' => self::RULES_VERSION,
                'content_hash' => $contentHash,
            ]);
            $assessment = DB::transaction(function () use ($event, $prior, $candidatePayload, $contentHash, $fingerprint): AttributionAssessment {
                $existing = AttributionAssessment::query()
                    ->where('workspace_id', $event->workspace_id)
                    ->where('idempotency_fingerprint', $fingerprint)
                    ->first();
                if ($existing instanceof AttributionAssessment) {
                    return $existing;
                }
                $assessment = AttributionAssessment::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $event->workspace_id,
                    'conversion_event_id' => $event->id,
                    'objective_id' => $prior->objective_id,
                    'objective_version_id' => $prior->objective_version_id,
                    'conversion_definition_version_id' => $prior->conversion_definition_version_id,
                    'selected_exposure_id' => $prior->selected_exposure_id,
                    'prior_assessment_id' => $prior->id,
                    'state' => AttributionAssessmentState::Reversed,
                    'method' => AttributionMethod::Reversal,
                    'window_started_at' => $prior->window_started_at,
                    'window_ended_at' => $prior->window_ended_at,
                    'candidate_count' => count($candidatePayload),
                    'rules_version' => self::RULES_VERSION,
                    'input_hash' => CanonicalObjectivePayload::hash([
                        'reversal_event_id' => (string) $event->id,
                        'prior_assessment_id' => (string) $prior->id,
                    ]),
                    'candidate_hash' => CanonicalObjectivePayload::hash($candidatePayload),
                    'content_hash' => $contentHash,
                    'reason_codes' => ['conversion_reversed'],
                    'actor_type' => 'system',
                    'actor_user_id' => null,
                    'idempotency_fingerprint' => $fingerprint,
                    'assessed_at' => now(),
                    'created_at' => now(),
                ]);
                foreach ($candidatePayload as $candidate) {
                    AttributionAssessmentCandidate::query()->create([
                        'id' => (string) Str::uuid(),
                        'workspace_id' => $event->workspace_id,
                        'attribution_assessment_id' => $assessment->id,
                        ...$candidate,
                        'created_at' => now(),
                    ]);
                }

                return $assessment->load('candidates');
            }, 3);
            $result[] = $assessment;
            $this->scheduleProgress((int) $assessment->workspace_id, (string) $assessment->objective_version_id);
        }

        return $result;
    }

    /** @return Collection<string, AttributionAssessment> */
    private function latestAssessmentsForEvent(int $workspaceId, string $eventId): Collection
    {
        $assessments = AttributionAssessment::query()
            ->where('workspace_id', $workspaceId)
            ->where('conversion_event_id', $eventId)
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
            ->keyBy('objective_version_id');
    }

    /** @return Collection<int, Exposure> */
    private function candidateExposures(
        ConversionEvent $event,
        ObjectiveVersion $version,
        ?Exposure $explicit,
    ): Collection {
        $query = Exposure::query()
            ->where('workspace_id', $event->workspace_id)
            ->where('objective_version_id', $version->id)
            ->with('action');
        if ($event->matched_account_id !== null) {
            $query->whereHas('action', fn ($action) => $action->where('account_id', $event->matched_account_id));
        } else {
            $query->whereRaw('1 = 0');
        }
        $candidates = $query->get();
        if ($explicit instanceof Exposure
            && (int) $explicit->workspace_id === (int) $event->workspace_id
            && ! $candidates->contains('id', $explicit->id)) {
            $explicit->loadMissing('action');
            $candidates->push($explicit);
        }

        return $candidates->unique('id')->sortBy([
            ['occurred_at', 'asc'],
            ['id', 'asc'],
        ])->values();
    }

    /** @return list<string> */
    private function globalIneligibilityReasons(ConversionEvent $event, ObjectiveVersion $version): array
    {
        $reasons = [];
        if ($event->getRawOriginal('state') !== ConversionEventState::Matched->value
            || $event->matched_account_id === null) {
            $reasons[] = 'conversion_event_not_exactly_matched';
        }
        if (in_array($event->getRawOriginal('verification_state'), [
            ConversionVerificationState::Unverified->value,
            ConversionVerificationState::Failed->value,
        ], true)) {
            $reasons[] = 'conversion_event_not_verified';
        }
        $occurredAt = $this->timestamp($event->getAttribute('occurred_at'));
        if ($occurredAt->lt($this->timestamp($version->getAttribute('starts_at')))
            || $occurredAt->gt($this->timestamp($version->getAttribute('ends_at')))) {
            $reasons[] = 'outside_objective_horizon';
        }
        if ($version->target_kind === ObjectiveTargetKind::Value
            && ($event->value === null || (string) $event->currency !== (string) $version->currency)) {
            $reasons[] = 'conversion_value_or_currency_mismatch';
        }

        return $reasons;
    }

    /** @return list<string> */
    private function candidateReasons(
        ConversionEvent $event,
        ObjectiveVersion $version,
        Exposure $exposure,
        CarbonImmutable $windowStartedAt,
        CarbonImmutable $windowEndedAt,
    ): array {
        $reasons = [];
        if ((string) $exposure->objective_version_id !== (string) $version->id) {
            $reasons[] = 'objective_version_mismatch';
        }
        $action = ExecutionPlanAction::query()
            ->where('workspace_id', $event->workspace_id)
            ->whereKey($exposure->execution_plan_action_id)
            ->first();
        if (! $action instanceof ExecutionPlanAction
            || (string) $action->conversion_definition_version_id !== (string) $event->conversion_definition_version_id) {
            $reasons[] = 'conversion_definition_version_mismatch';
        }
        if (! $action instanceof ExecutionPlanAction || (int) $action->account_id !== (int) $event->matched_account_id) {
            $reasons[] = 'account_mismatch';
        }
        $exposureOccurredAt = $this->timestamp($exposure->getAttribute('occurred_at'));
        if ($exposureOccurredAt->lt($windowStartedAt)) {
            $reasons[] = 'before_attribution_window';
        }
        if ($exposureOccurredAt->gt($windowEndedAt)) {
            $reasons[] = 'exposure_after_conversion';
        }

        return $reasons;
    }

    /**
     * @return array{present:bool,valid:bool,exposure:?Exposure,reason_codes:list<string>}
     */
    private function resolveExplicitCorrelation(ConversionEvent $event, ObjectiveVersion $version): array
    {
        $rawProperties = $event->getAttribute('properties');
        $properties = is_array($rawProperties) ? $rawProperties : [];
        $hasExposure = array_key_exists('outboss_exposure_id', $properties);
        $hasAction = array_key_exists('outboss_action_id', $properties);
        if (! $hasExposure && ! $hasAction) {
            return ['present' => false, 'valid' => true, 'exposure' => null, 'reason_codes' => []];
        }

        $reasons = [];
        if ($event->getRawOriginal('source_type') !== ConversionSourceType::Webhook->value
            || $event->getRawOriginal('verification_state') !== ConversionVerificationState::Verified->value) {
            $reasons[] = 'explicit_correlation_not_signed';
        }
        $definitionVersion = ConversionDefinitionVersion::query()
            ->where('workspace_id', $event->workspace_id)
            ->whereKey($version->conversion_definition_version_id)
            ->first();
        $definitions = data_get($definitionVersion?->property_schema, 'properties', []);
        foreach (['outboss_exposure_id' => $hasExposure, 'outboss_action_id' => $hasAction] as $key => $present) {
            if (! $present) {
                continue;
            }
            if (! is_string($properties[$key]) || trim($properties[$key]) === '') {
                $reasons[] = 'explicit_correlation_invalid';
            }
            if (! is_array($definitions) || data_get($definitions, "{$key}.type") !== 'string') {
                $reasons[] = 'explicit_correlation_schema_missing';
            }
        }

        $byExposure = $hasExposure && is_string($properties['outboss_exposure_id'])
            ? Exposure::query()
                ->where('workspace_id', $event->workspace_id)
                ->whereKey($properties['outboss_exposure_id'])
                ->first()
            : null;
        $byAction = $hasAction && is_string($properties['outboss_action_id'])
            ? Exposure::query()
                ->where('workspace_id', $event->workspace_id)
                ->where('execution_plan_action_id', $properties['outboss_action_id'])
                ->first()
            : null;
        if (($hasExposure && ! $byExposure instanceof Exposure) || ($hasAction && ! $byAction instanceof Exposure)) {
            $reasons[] = 'explicit_correlation_not_found';
        }
        if ($byExposure instanceof Exposure && $byAction instanceof Exposure && (string) $byExposure->id !== (string) $byAction->id) {
            $reasons[] = 'explicit_correlation_conflict';
        }

        return [
            'present' => true,
            'valid' => $reasons === [],
            'exposure' => $byExposure ?? $byAction,
            'reason_codes' => array_values(array_unique($reasons)),
        ];
    }

    private function scheduleProgress(int $workspaceId, string $objectiveVersionId): void
    {
        try {
            RebuildObjectiveProgress::dispatch($workspaceId, $objectiveVersionId);
        } catch (Throwable $exception) {
            Log::warning('Objective progress projection dispatch failed.', [
                'workspace_id' => $workspaceId,
                'objective_version_id' => $objectiveVersionId,
                'exception' => $exception::class,
            ]);
        }
    }

    /** @param list<array<string, mixed>> $candidateRows */
    private function isEligibleCorrelation(array $candidateRows, Exposure $correlation): bool
    {
        foreach ($candidateRows as $row) {
            if (($row['exposure'] ?? null) instanceof Exposure
                && (string) $row['exposure']->id === (string) $correlation->id
                && ($row['eligible'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return CarbonImmutable::parse((string) $value);
    }
}
