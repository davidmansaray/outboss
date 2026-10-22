<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\AttributionAssessmentState;
use App\Domain\Objectives\Enums\AttributionMethod;
use App\Domain\Objectives\Exceptions\AttributionConflict;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Jobs\Objectives\RebuildObjectiveProgress;
use App\Models\AttributionAssessment;
use App\Models\AttributionAssessmentCandidate;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Append an owner decision without altering system attribution history. */
final class OverrideObjectiveAttribution
{
    public function handle(
        AttributionAssessment $prior,
        User $actor,
        ?string $exposureId,
        string $reason,
        string $idempotencyKey,
    ): AttributionAssessment {
        $reason = trim($reason);
        $idempotencyKey = trim($idempotencyKey);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'A bounded attribution override reason is required.']);
        }
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 160) {
            throw ValidationException::withMessages(['idempotency_key' => 'A bounded idempotency key is required.']);
        }

        $workspace = Workspace::query()->whereKey($prior->workspace_id)->firstOrFail();
        if (! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only a workspace owner may override attribution.');
        }
        $prior = AttributionAssessment::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($prior->id)
            ->with('candidates')
            ->firstOrFail();
        $selectedCandidate = null;
        if ($exposureId !== null) {
            foreach ($prior->candidates as $candidate) {
                if ((string) $candidate->exposure_id === $exposureId) {
                    $selectedCandidate = $candidate;
                    break;
                }
            }
            if (! $selectedCandidate instanceof AttributionAssessmentCandidate || ! $selectedCandidate->eligible) {
                throw ValidationException::withMessages([
                    'exposure_id' => 'Choose an eligible exposure from the recorded candidate set.',
                ]);
            }
        }

        $inputHash = CanonicalObjectivePayload::hash([
            'version' => 'objective_attribution_override_v1',
            'prior_assessment_id' => (string) $prior->id,
            'selected_exposure_id' => $exposureId,
            'reason' => $reason,
        ]);
        $idempotencyFingerprint = CanonicalObjectivePayload::hash([
            'version' => 'objective_attribution_override_key_v1',
            'workspace_id' => (int) $workspace->id,
            'prior_assessment_id' => (string) $prior->id,
            'idempotency_key' => $idempotencyKey,
        ]);
        $replay = AttributionAssessment::query()
            ->where('workspace_id', $workspace->id)
            ->where('idempotency_fingerprint', $idempotencyFingerprint)
            ->first();
        if ($replay instanceof AttributionAssessment) {
            if (! hash_equals((string) $replay->input_hash, $inputHash)) {
                throw new AttributionConflict('attribution_override_idempotency_mismatch');
            }

            return $replay->load('candidates');
        }
        $latest = AttributionAssessment::query()
            ->where('workspace_id', $workspace->id)
            ->where('conversion_event_id', $prior->conversion_event_id)
            ->where('objective_version_id', $prior->objective_version_id)
            ->latest('assessed_at')
            ->latest('created_at')
            ->latest('id')
            ->first();
        if (! $latest instanceof AttributionAssessment || (string) $latest->id !== (string) $prior->id) {
            throw new AttributionConflict('attribution_override_stale');
        }
        $reasonCodes = [
            $exposureId === null ? 'human_override_no_attribution' : 'human_override_selected',
            'owner_reason:'.$reason,
        ];
        $contentHash = CanonicalObjectivePayload::hash([
            'input_hash' => $inputHash,
            'state' => AttributionAssessmentState::ManuallyOverridden,
            'method' => AttributionMethod::HumanOverride,
            'reason_codes' => $reasonCodes,
        ]);
        $candidatePayload = [];
        foreach ($prior->candidates->sortBy('exposure_id') as $candidate) {
            $candidatePayload[] = [
                'exposure_id' => (string) $candidate->exposure_id,
                'eligible' => (bool) $candidate->eligible,
                'reason_codes' => $candidate->reason_codes,
                'time_distance_seconds' => (int) $candidate->time_distance_seconds,
                'explicit_correlation' => (bool) $candidate->explicit_correlation,
            ];
        }

        $assessment = DB::transaction(function () use (
            $workspace,
            $prior,
            $actor,
            $exposureId,
            $inputHash,
            $idempotencyFingerprint,
            $reasonCodes,
            $contentHash,
            $candidatePayload,
        ): AttributionAssessment {
            $existing = AttributionAssessment::query()
                ->where('workspace_id', $workspace->id)
                ->where('idempotency_fingerprint', $idempotencyFingerprint)
                ->first();
            if ($existing instanceof AttributionAssessment) {
                if (! hash_equals((string) $existing->input_hash, $inputHash)) {
                    throw new AttributionConflict('attribution_override_idempotency_mismatch');
                }

                return $existing->load('candidates');
            }

            $assessment = AttributionAssessment::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'conversion_event_id' => $prior->conversion_event_id,
                'objective_id' => $prior->objective_id,
                'objective_version_id' => $prior->objective_version_id,
                'conversion_definition_version_id' => $prior->conversion_definition_version_id,
                'selected_exposure_id' => $exposureId,
                'prior_assessment_id' => $prior->id,
                'state' => AttributionAssessmentState::ManuallyOverridden,
                'method' => AttributionMethod::HumanOverride,
                'window_started_at' => $prior->window_started_at,
                'window_ended_at' => $prior->window_ended_at,
                'candidate_count' => count($candidatePayload),
                'rules_version' => AssessObjectiveAttribution::RULES_VERSION,
                'input_hash' => $inputHash,
                'candidate_hash' => CanonicalObjectivePayload::hash($candidatePayload),
                'content_hash' => $contentHash,
                'reason_codes' => $reasonCodes,
                'actor_type' => 'human',
                'actor_user_id' => $actor->id,
                'idempotency_fingerprint' => $idempotencyFingerprint,
                'assessed_at' => now(),
                'created_at' => now(),
            ]);
            foreach ($candidatePayload as $candidate) {
                AttributionAssessmentCandidate::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'attribution_assessment_id' => $assessment->id,
                    ...$candidate,
                    'created_at' => now(),
                ]);
            }

            return $assessment->load('candidates');
        }, 3);

        try {
            RebuildObjectiveProgress::dispatch(
                (int) $assessment->workspace_id,
                (string) $assessment->objective_version_id,
            );
        } catch (Throwable $exception) {
            Log::warning('Objective progress projection dispatch failed.', [
                'workspace_id' => (int) $assessment->workspace_id,
                'objective_version_id' => (string) $assessment->objective_version_id,
                'exception' => $exception::class,
            ]);
        }

        return $assessment;
    }
}
