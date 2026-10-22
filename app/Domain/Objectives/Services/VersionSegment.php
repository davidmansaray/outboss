<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\SegmentCriteriaValidator;
use App\Models\Segment;
use App\Models\SegmentVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class VersionSegment
{
    public function __construct(private readonly SegmentCriteriaValidator $validator) {}

    /** @param array<string, mixed> $input */
    public function handle(Segment $segment, User $actor, array $input, int $expectedLockVersion): SegmentVersion
    {
        return DB::transaction(function () use ($segment, $actor, $input, $expectedLockVersion): SegmentVersion {
            $locked = Segment::query()
                ->whereKey($segment->id)
                ->where('workspace_id', $segment->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $locked->lock_version !== $expectedLockVersion) {
                throw new \RuntimeException('segment_version_conflict');
            }
            $current = SegmentVersion::query()->whereKey($locked->current_version_id)->firstOrFail();
            $payload = [
                'name' => trim((string) ($input['name'] ?? $current->name)),
                'description' => $input['description'] ?? $current->description,
                'criteria' => $this->validator->validate((array) ($input['criteria'] ?? $current->criteria)),
                'criteria_schema_version' => SegmentCriteriaValidator::SCHEMA_VERSION,
                'missing_data_policy' => 'uncertain',
                'buyer_roles' => array_values((array) ($input['buyer_roles'] ?? $current->buyer_roles)),
                'required_qualification_outcomes' => array_values((array) ($input['required_qualification_outcomes'] ?? $current->required_qualification_outcomes)),
                'evaluator_version' => 'segment_evaluator_v1',
            ];
            $hash = CanonicalObjectivePayload::hash($payload);
            if (hash_equals((string) $current->content_hash, $hash)) {
                return $current;
            }
            $version = SegmentVersion::query()->create([
                'workspace_id' => $locked->workspace_id,
                'segment_id' => $locked->id,
                'version' => ((int) $current->version) + 1,
                ...$payload,
                'content_hash' => $hash,
                'created_by_user_id' => $actor->id,
            ]);
            $locked->forceFill(['current_version_id' => $version->id, 'lock_version' => $expectedLockVersion + 1])->save();

            return $version;
        }, 3);
    }
}
