<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\ObjectiveStatus;
use App\Domain\Objectives\Enums\ObjectiveTargetKind;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\AudienceSnapshot;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use App\Models\Objective;
use App\Models\ObjectiveVersion;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\SegmentVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class VersionObjective
{
    public function __construct(private readonly ForecastObjectiveInventory $forecast) {}

    /** @param array<string, mixed> $input */
    public function handle(Objective $objective, User $actor, array $input, int $expectedLockVersion): ObjectiveVersion
    {
        return DB::transaction(function () use ($objective, $actor, $input, $expectedLockVersion): ObjectiveVersion {
            $locked = Objective::query()->where('workspace_id', $objective->workspace_id)
                ->whereKey($objective->id)->lockForUpdate()->firstOrFail();
            $workspace = $locked->workspace()->firstOrFail();
            if (! $workspace->isOwner($actor)) {
                throw new AuthorizationException('Only the workspace owner can version an Objective.');
            }
            if ((int) $locked->lock_version !== $expectedLockVersion) {
                throw new RuntimeException('stale_version');
            }
            if ($locked->status !== ObjectiveStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Only a draft Objective can be configured.']);
            }

            $payload = $this->validatedPayload((int) $locked->workspace_id, (string) $locked->id, $input);
            $current = $locked->currentVersion()->first();
            $contentHash = CanonicalObjectivePayload::hash($payload);
            if ($current !== null && hash_equals((string) $current->content_hash, $contentHash)) {
                return $current;
            }
            $version = ObjectiveVersion::query()->create([
                'workspace_id' => $locked->workspace_id,
                'objective_id' => $locked->id,
                'version' => ((int) $locked->versions()->max('version')) + 1,
                ...$payload,
                'content_hash' => $contentHash,
                'created_by_user_id' => $actor->id,
            ]);
            $updated = Objective::query()->whereKey($locked->id)
                ->where('lock_version', $expectedLockVersion)
                ->update([
                    'current_version_id' => $version->id,
                    'last_actor_user_id' => $actor->id,
                    'lock_version' => $expectedLockVersion + 1,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('stale_version');
            }

            return $version;
        }, 3);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validatedPayload(int $workspaceId, string $objectiveId, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            throw ValidationException::withMessages(['name' => 'The Objective name must be between 1 and 160 characters.']);
        }
        $kind = ObjectiveTargetKind::tryFrom((string) ($input['target_kind'] ?? ''));
        if ($kind === null) {
            throw ValidationException::withMessages(['target_kind' => 'Choose count or value.']);
        }
        $targetCount = isset($input['target_count']) ? (int) $input['target_count'] : null;
        $targetValue = isset($input['target_value']) ? number_format((float) $input['target_value'], 2, '.', '') : null;
        $currency = isset($input['currency']) ? strtoupper(trim((string) $input['currency'])) : null;
        if ($kind === ObjectiveTargetKind::Count && ($targetCount === null || $targetCount < 1 || $targetValue !== null)) {
            throw ValidationException::withMessages(['target_count' => 'A count Objective requires only a positive target count.']);
        }
        if ($kind === ObjectiveTargetKind::Value && ($targetValue === null || (float) $targetValue <= 0 || $targetCount !== null || preg_match('/^[A-Z]{3}$/', (string) $currency) !== 1)) {
            throw ValidationException::withMessages(['target_value' => 'A value Objective requires only a positive value and three-letter currency.']);
        }
        if ($kind === ObjectiveTargetKind::Count) {
            $currency = null;
        }

        try {
            $startsAt = Carbon::parse((string) ($input['starts_at'] ?? ''));
            $endsAt = Carbon::parse((string) ($input['ends_at'] ?? ''));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['starts_at' => 'Provide valid Objective start and end timestamps.']);
        }
        if (! $startsAt->lessThan($endsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'The Objective must end after it starts.']);
        }
        if (! $endsAt->isFuture()) {
            throw ValidationException::withMessages(['ends_at' => 'The Objective must end in the future.']);
        }
        $maximumHorizonDays = (int) config('objectives.safety.maximum_horizon_days');
        if ($maximumHorizonDays < 1) {
            throw ValidationException::withMessages(['ends_at' => 'The Objective horizon safety limit is unavailable.']);
        }
        if ($endsAt->greaterThan($startsAt->copy()->addDays($maximumHorizonDays))) {
            throw ValidationException::withMessages(['ends_at' => 'The Objective horizon exceeds the pilot duration limit.']);
        }

        $limits = [
            'maximum_people' => (int) ($input['maximum_people'] ?? 0),
            'maximum_accounts' => (int) ($input['maximum_accounts'] ?? 0),
            'maximum_messages' => (int) ($input['maximum_messages'] ?? 0),
            'maximum_ai_cost_micros' => (int) ($input['maximum_ai_cost_micros'] ?? 0),
        ];
        $serverLimits = [
            'maximum_people' => (int) config('objectives.safety.maximum_people'),
            'maximum_accounts' => (int) config('objectives.safety.maximum_accounts'),
            'maximum_messages' => (int) config('objectives.safety.maximum_messages'),
            'maximum_ai_cost_micros' => (int) config('objectives.safety.maximum_ai_cost_micros'),
        ];
        foreach ($limits as $key => $value) {
            if ($value < 1 || $value > $serverLimits[$key]) {
                throw ValidationException::withMessages([$key => "{$key} must be positive and no greater than the server safety cap."]);
            }
        }
        if ($limits['maximum_messages'] > $limits['maximum_people']) {
            throw ValidationException::withMessages(['maximum_messages' => 'The pilot permits at most one message per Person.']);
        }

        $attributionWindow = (int) ($input['attribution_window_days'] ?? 0);
        if ($attributionWindow < 1 || $attributionWindow > (int) config('objectives.safety.maximum_attribution_window_days')) {
            throw ValidationException::withMessages(['attribution_window_days' => 'The attribution window is outside the pilot limit.']);
        }
        $confidence = (float) ($input['minimum_qualification_confidence'] ?? -1);
        $evidenceAge = (int) ($input['maximum_evidence_age_days'] ?? 0);
        if ($confidence < 0 || $confidence > 1 || $evidenceAge < 1 || $evidenceAge > 365) {
            throw ValidationException::withMessages(['qualification' => 'Qualification confidence or evidence age is invalid.']);
        }
        $outcomes = array_values(array_unique(array_filter(
            is_array($input['required_qualification_outcomes'] ?? null) ? $input['required_qualification_outcomes'] : [],
            fn (mixed $value): bool => is_string($value) && in_array($value, ['contact', 'withhold', 'research_further', 'insufficient_evidence', 'needs_review'], true),
        )));
        if ($outcomes === []) {
            throw ValidationException::withMessages(['required_qualification_outcomes' => 'Choose at least one qualification outcome.']);
        }
        $authority = is_array($input['authority_envelope'] ?? null) ? $input['authority_envelope'] : [];
        if (($authority['approval_required'] ?? null) !== true) {
            throw ValidationException::withMessages(['authority_envelope' => 'The S3 pilot requires explicit approval authority.']);
        }

        $snapshot = AudienceSnapshot::query()->where('workspace_id', $workspaceId)
            ->whereKey($input['audience_snapshot_id'] ?? null)->firstOrFail();
        if ((string) $snapshot->objective_id !== $objectiveId || $snapshot->status !== 'frozen') {
            throw ValidationException::withMessages(['audience_snapshot_id' => 'Use a frozen snapshot created for this Objective.']);
        }
        $segmentVersion = SegmentVersion::query()->where('workspace_id', $workspaceId)
            ->whereKey($input['segment_version_id'] ?? null)->firstOrFail();
        if ((string) $snapshot->segment_version_id !== (string) $segmentVersion->id) {
            throw ValidationException::withMessages(['segment_version_id' => 'The Segment Version does not own this snapshot.']);
        }
        if ($snapshot->included_people_count > $limits['maximum_people'] || $snapshot->included_accounts_count > $limits['maximum_accounts']) {
            throw ValidationException::withMessages(['audience_snapshot_id' => 'The frozen audience exceeds this Objective safety envelope.']);
        }

        $definition = ConversionDefinition::query()->where('workspace_id', $workspaceId)
            ->whereKey($input['conversion_definition_id'] ?? null)->firstOrFail();
        $definitionVersion = ConversionDefinitionVersion::query()->where('workspace_id', $workspaceId)
            ->where('conversion_definition_id', $definition->id)
            ->whereKey($input['conversion_definition_version_id'] ?? null)->firstOrFail();
        $offer = Offer::query()->where('workspace_id', $workspaceId)->whereKey($input['offer_id'] ?? null)->firstOrFail();
        $offerVersion = OfferVersion::query()->where('workspace_id', $workspaceId)->where('offer_id', $offer->id)
            ->whereKey($input['offer_version_id'] ?? null)->firstOrFail();
        $assumptions = is_array($input['forecast_assumptions'] ?? null) ? $input['forecast_assumptions'] : [];

        return [
            'name' => $name,
            'description' => isset($input['description']) ? trim((string) $input['description']) : null,
            'conversion_definition_id' => $definition->id,
            'conversion_definition_version_id' => $definitionVersion->id,
            'segment_version_id' => $segmentVersion->id,
            'audience_snapshot_id' => $snapshot->id,
            'offer_id' => $offer->id,
            'offer_version_id' => $offerVersion->id,
            'target_kind' => $kind->value,
            'target_count' => $targetCount,
            'target_value' => $targetValue,
            'currency' => $currency,
            'starts_at' => $startsAt->utc()->toAtomString(),
            'ends_at' => $endsAt->utc()->toAtomString(),
            'attribution_window_days' => $attributionWindow,
            ...$limits,
            'required_qualification_outcomes' => $outcomes,
            'minimum_qualification_confidence' => number_format($confidence, 4, '.', ''),
            'maximum_evidence_age_days' => $evidenceAge,
            'exclusions' => is_array($input['exclusions'] ?? null) ? $input['exclusions'] : [],
            'authority_envelope' => $authority,
            'forecast_assumptions' => $assumptions,
            'forecast_projection' => $this->forecast->handle($snapshot, $kind, $targetCount, $targetValue, $currency, $assumptions),
            'schema_version' => 'objective_v1',
        ];
    }
}
