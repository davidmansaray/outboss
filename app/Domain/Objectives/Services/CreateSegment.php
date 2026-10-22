<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\SegmentStatus;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\SegmentCriteriaValidator;
use App\Models\Segment;
use App\Models\SegmentVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateSegment
{
    public function __construct(private readonly SegmentCriteriaValidator $validator) {}

    /** @param array<string, mixed> $criteria
     * @param  list<string>  $buyerRoles
     * @param  list<string>  $requiredQualificationOutcomes
     */
    public function handle(
        Workspace $workspace,
        User $actor,
        string $name,
        array $criteria,
        ?string $description = null,
        array $buyerRoles = [],
        array $requiredQualificationOutcomes = ['contact'],
    ): Segment {
        $criteria = $this->validator->validate($criteria);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw ValidationException::withMessages(['name' => 'The segment name must be between 1 and 160 characters.']);
        }

        return DB::transaction(function () use ($workspace, $actor, $name, $criteria, $description, $buyerRoles, $requiredQualificationOutcomes): Segment {
            $segment = Segment::query()->create([
                'workspace_id' => $workspace->id,
                'status' => SegmentStatus::Draft,
                'created_by_user_id' => $actor->id,
                'lock_version' => 1,
            ]);
            $payload = [
                'name' => $name,
                'description' => $description === null ? null : trim($description),
                'criteria' => $criteria,
                'criteria_schema_version' => SegmentCriteriaValidator::SCHEMA_VERSION,
                'missing_data_policy' => 'uncertain',
                'buyer_roles' => array_values(array_unique($buyerRoles)),
                'required_qualification_outcomes' => array_values(array_unique($requiredQualificationOutcomes)),
                'evaluator_version' => 'segment_evaluator_v1',
            ];
            $version = SegmentVersion::query()->create([
                'workspace_id' => $workspace->id,
                'segment_id' => $segment->id,
                'version' => 1,
                ...$payload,
                'content_hash' => CanonicalObjectivePayload::hash($payload),
                'created_by_user_id' => $actor->id,
            ]);
            $segment->forceFill(['current_version_id' => $version->id])->save();

            return $segment->fresh('currentVersion');
        }, 3);
    }
}
