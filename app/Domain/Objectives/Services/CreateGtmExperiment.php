<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\GtmExperimentArmKey;
use App\Domain\Objectives\Enums\GtmExperimentStatus;
use App\Domain\Objectives\Enums\StrategyStatus;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\DeterministicAccountAssignment;
use App\Domain\Objectives\Support\DeterministicMessageTemplate;
use App\Models\GtmExperiment;
use App\Models\GtmExperimentArm;
use App\Models\ObjectiveVersion;
use App\Models\Strategy;
use App\Models\StrategyVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateGtmExperiment
{
    /**
     * Declared dimensions map only to real outbound Strategy fields. Because
     * the current schema has no separate copy field, copy conservatively means
     * problem_frame; accepting a free-standing copy declaration would create
     * a treatment label that cannot affect rendered output.
     */
    public const DIFFERENCE_DIMENSIONS = [
        'positioning' => ['positioning'],
        'proof' => ['proof_summary', 'proof_claim_ids'],
        'cta' => ['cta_kind', 'cta_text'],
        'copy' => ['problem_frame'],
    ];

    public function __construct(private readonly DeterministicMessageTemplate $templates) {}

    /** @param array<string, mixed> $input */
    public function handle(Workspace $workspace, User $actor, array $input): GtmExperiment
    {
        if (! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can create commercial experiments.');
        }

        return DB::transaction(function () use ($workspace, $actor, $input): GtmExperiment {
            $objectiveVersion = ObjectiveVersion::query()->where('workspace_id', $workspace->id)
                ->whereKey($input['objective_version_id'] ?? null)->with('objective')->firstOrFail();
            if ((string) $objectiveVersion->objective->current_version_id !== (string) $objectiveVersion->id) {
                throw ValidationException::withMessages(['objective_version_id' => 'Use the current Objective Version.']);
            }
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 160) {
                throw ValidationException::withMessages(['name' => 'The experiment name must be between 1 and 160 characters.']);
            }
            $armInputs = $input['arms'] ?? null;
            if (! is_array($armInputs) || ! in_array(count($armInputs), [1, 2], true)) {
                throw ValidationException::withMessages(['arms' => 'Use exactly one control arm or one control and one treatment arm.']);
            }
            $expected = count($armInputs) === 1
                ? [GtmExperimentArmKey::Control->value => 10000]
                : [GtmExperimentArmKey::Control->value => 5000, GtmExperimentArmKey::Treatment->value => 5000];
            $arms = [];
            foreach ($armInputs as $armInput) {
                if (! is_array($armInput)) {
                    throw ValidationException::withMessages(['arms' => 'Each arm must be an object.']);
                }
                $key = (string) ($armInput['key'] ?? '');
                if (! array_key_exists($key, $expected) || isset($arms[$key])
                    || (int) ($armInput['allocation_basis_points'] ?? 0) !== $expected[$key]) {
                    throw ValidationException::withMessages(['arms' => 'Pilot allocation must be control 100% or control/treatment 50/50.']);
                }
                $strategyVersion = StrategyVersion::query()->where('workspace_id', $workspace->id)
                    ->whereKey($armInput['strategy_version_id'] ?? null)->with('strategy')->firstOrFail();
                if ((string) $strategyVersion->strategy->current_version_id !== (string) $strategyVersion->id
                    || (string) $strategyVersion->objective_version_id !== (string) $objectiveVersion->id
                    || (string) $strategyVersion->audience_snapshot_id !== (string) $objectiveVersion->audience_snapshot_id
                    || (string) $strategyVersion->offer_version_id !== (string) $objectiveVersion->offer_version_id) {
                    throw ValidationException::withMessages(['arms' => 'Every arm must use current Strategy inputs matching this Objective.']);
                }
                $differences = is_array($armInput['declared_differences'] ?? null) ? $armInput['declared_differences'] : [];
                if (array_diff(array_keys($differences), ['positioning', 'proof', 'cta', 'copy']) !== []) {
                    throw ValidationException::withMessages(['arms' => 'Arm differences are limited to positioning, proof, CTA and copy.']);
                }
                foreach ($differences as $dimension => $description) {
                    if (! is_string($description) || $description === ''
                        || ! hash_equals($description, trim($description))
                        || strlen($description) > 500
                        || preg_match('/\p{Cc}|[\x{0085}\x{2028}\x{2029}]/u', $description) !== 0) {
                        throw ValidationException::withMessages([
                            'arms' => "The {$dimension} difference declaration must be exact bounded plain text.",
                        ]);
                    }
                }
                ksort($differences, SORT_STRING);
                if ($key === GtmExperimentArmKey::Control->value && $differences !== []) {
                    throw ValidationException::withMessages(['arms' => 'The control arm cannot declare a treatment difference.']);
                }
                if ($key === GtmExperimentArmKey::Treatment->value && $differences === []) {
                    throw ValidationException::withMessages(['arms' => 'The treatment arm must declare its difference.']);
                }
                $arms[$key] = [
                    'strategy' => $strategyVersion,
                    'label' => mb_substr(trim((string) ($armInput['label'] ?? ucfirst($key))), 0, 100),
                    'allocation_basis_points' => $expected[$key],
                    'declared_differences' => $differences,
                ];
            }
            ksort($arms);
            $expectedKeys = array_keys($expected);
            sort($expectedKeys);
            if (array_keys($arms) !== $expectedKeys) {
                throw ValidationException::withMessages(['arms' => 'The required pilot arm keys are missing.']);
            }
            if (isset($arms[GtmExperimentArmKey::Treatment->value])) {
                $controlArm = $arms[GtmExperimentArmKey::Control->value] ?? null;
                if (! is_array($controlArm)) {
                    throw ValidationException::withMessages(['arms' => 'The required control arm is missing.']);
                }
                $this->assertTreatmentIntegrity(
                    $controlArm,
                    $arms[GtmExperimentArmKey::Treatment->value],
                );
            }

            $experiment = GtmExperiment::query()->create([
                'workspace_id' => $workspace->id,
                'objective_id' => $objectiveVersion->objective_id,
                'objective_version_id' => $objectiveVersion->id,
                'audience_snapshot_id' => $objectiveVersion->audience_snapshot_id,
                'conversion_definition_id' => $objectiveVersion->conversion_definition_id,
                'conversion_definition_version_id' => $objectiveVersion->conversion_definition_version_id,
                'name' => $name,
                'hypothesis' => isset($input['hypothesis']) ? mb_substr(trim((string) $input['hypothesis']), 0, 4000) : null,
                'status' => GtmExperimentStatus::Draft,
                'assignment_unit' => 'account',
                'assignment_algorithm_version' => DeterministicAccountAssignment::ALGORITHM_VERSION,
                'allocation_version' => 1,
                'guardrails' => is_array($input['guardrails'] ?? null) ? $input['guardrails'] : [],
                'starts_at' => $objectiveVersion->starts_at,
                'ends_at' => $objectiveVersion->ends_at,
                'lock_version' => 1,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            foreach ($arms as $key => $arm) {
                /** @var StrategyVersion $strategyVersion */
                $strategyVersion = $arm['strategy'];
                GtmExperimentArm::query()->create([
                    'workspace_id' => $workspace->id,
                    'gtm_experiment_id' => $experiment->id,
                    'strategy_version_id' => $strategyVersion->id,
                    'offer_version_id' => $objectiveVersion->offer_version_id,
                    'key' => $key,
                    'label' => $arm['label'],
                    'allocation_basis_points' => $arm['allocation_basis_points'],
                    'declared_differences' => $arm['declared_differences'],
                ]);
                Strategy::query()->whereKey($strategyVersion->strategy_id)->update([
                    'status' => StrategyStatus::Active->value,
                    'updated_by_user_id' => $actor->id,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_at' => now(),
                ]);
            }

            return $experiment->fresh('arms');
        }, 3);
    }

    /**
     * @param  array{strategy:StrategyVersion,label:string,allocation_basis_points:int,declared_differences:array<string,string>}  $control
     * @param  array{strategy:StrategyVersion,label:string,allocation_basis_points:int,declared_differences:array<string,string>}  $treatment
     */
    private function assertTreatmentIntegrity(array $control, array $treatment): void
    {
        $controlContract = $this->templates->strategyContract($control['strategy']);
        $treatmentContract = $this->templates->strategyContract($treatment['strategy']);
        if (hash_equals($controlContract['projection_hash'], $treatmentContract['projection_hash'])) {
            throw ValidationException::withMessages([
                'arms' => 'The treatment Strategy is output-equivalent to the control Strategy.',
            ]);
        }

        $controlProjection = $controlContract['projection'];
        $treatmentProjection = $treatmentContract['projection'];
        $changedFields = [];
        foreach (DeterministicMessageTemplate::STRATEGY_PROJECTION_FIELDS as $field) {
            if (CanonicalObjectivePayload::hash([$controlProjection[$field]])
                !== CanonicalObjectivePayload::hash([$treatmentProjection[$field]])) {
                $changedFields[] = $field;
            }
        }

        $actualDimensions = [];
        $mappedFields = [];
        foreach (self::DIFFERENCE_DIMENSIONS as $dimension => $fields) {
            $mappedFields = [...$mappedFields, ...$fields];
            if (array_intersect($fields, $changedFields) !== []) {
                $actualDimensions[] = $dimension;
            }
        }
        $invariantChanges = array_values(array_diff($changedFields, $mappedFields));
        if ($invariantChanges !== []) {
            throw ValidationException::withMessages([
                'arms' => 'Treatment arms must preserve channel, message count, limits, placeholders, stop conditions and schema invariants.',
            ]);
        }

        sort($actualDimensions, SORT_STRING);
        $declaredDimensions = array_keys($treatment['declared_differences']);
        sort($declaredDimensions, SORT_STRING);
        if (array_diff($actualDimensions, $declaredDimensions) !== []) {
            throw ValidationException::withMessages([
                'arms' => 'Every actual outbound Strategy difference must be declared on the treatment arm.',
            ]);
        }
        if (array_diff($declaredDimensions, $actualDimensions) !== []) {
            throw ValidationException::withMessages([
                'arms' => 'Every treatment declaration must correspond to an actual outbound Strategy difference.',
            ]);
        }
    }
}
