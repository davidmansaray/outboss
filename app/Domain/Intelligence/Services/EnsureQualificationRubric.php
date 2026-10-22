<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\Enums\QualificationRubricState;
use App\Domain\Intelligence\Support\CanonicalIntelligence;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\GtmProfileVersion;
use App\Models\OfferVersion;
use App\Models\QualificationRubric;
use App\Models\QualificationRubricVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Seed one editable workspace rubric; versions are immutable snapshots. */
final class EnsureQualificationRubric
{
    /** @param array<string,mixed>|null $definition */
    public function handle(
        Workspace $workspace,
        ?User $actor = null,
        ?GtmProfileVersion $gtmProfileVersion = null,
        ?OfferVersion $offerVersion = null,
        ?array $definition = null,
    ): QualificationRubricVersion {
        return DB::transaction(function () use ($workspace, $actor, $gtmProfileVersion, $offerVersion, $definition): QualificationRubricVersion {
            $rubric = QualificationRubric::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->first();
            if ($rubric === null) {
                $rubric = QualificationRubric::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'name' => (string) config('intelligence.qualification.rubric_name', 'Default account qualification'),
                    'state' => QualificationRubricState::Active,
                    'current_version_id' => null,
                    'lock_version' => 0,
                ]);
            }
            $current = $rubric->current_version_id === null
                ? null
                : QualificationRubricVersion::query()->where('workspace_id', $workspace->id)->whereKey($rubric->current_version_id)->first();
            if ($current !== null && $definition === null && ($gtmProfileVersion === null || (string) $current->gtm_profile_version_id === (string) $gtmProfileVersion->id) && ($offerVersion === null || (string) $current->offer_version_id === (string) $offerVersion->id)) {
                return $current;
            }

            $definition = $this->definition($definition, $gtmProfileVersion);
            $versionNumber = (int) $rubric->versions()->max('version') + 1;
            $contentHash = CanonicalIntelligence::hash([
                'rubric_id' => (string) $rubric->id,
                'version' => $versionNumber,
                'gtm_profile_version_id' => $gtmProfileVersion?->id,
                'offer_version_id' => $offerVersion?->id,
                'definition' => $definition,
            ]);
            $version = QualificationRubricVersion::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'rubric_id' => $rubric->id,
                'version' => $versionNumber,
                'schema_version' => 1,
                'gtm_profile_version_id' => $gtmProfileVersion?->id,
                'offer_version_id' => $offerVersion?->id,
                'dimensions' => $definition['dimensions'],
                'required_facts' => $definition['required_facts'],
                'disqualifiers' => $definition['disqualifiers'],
                'confidence_bands' => $definition['confidence_bands'],
                'sensitive_predicates' => $definition['sensitive_predicates'],
                'decision_rules' => $definition['decision_rules'],
                'content_hash' => $contentHash,
                'actor_snapshot' => $this->actorSnapshot($actor),
                'actor_user_id' => $actor?->id,
                'created_at' => now(),
            ]);
            $rubric->forceFill([
                'current_version_id' => $version->id,
                'state' => QualificationRubricState::Active,
                'lock_version' => (int) $rubric->lock_version + 1,
            ])->save();

            return $version;
        }, 3);
    }

    /**
     * @param  array<string,mixed>|null  $definition
     * @return array<string,mixed>
     */
    private function definition(?array $definition, ?GtmProfileVersion $profile): array
    {
        $payload = ModelAttribute::array($profile?->payload);
        $required = data_get($payload, 'qualification.evidence_requirements', []);
        $disqualifiers = data_get($payload, 'qualification.business_disqualifiers', []);
        if (! is_array($required) || ! array_is_list($required)) {
            $required = [];
        }
        if (! is_array($disqualifiers) || ! array_is_list($disqualifiers)) {
            $disqualifiers = [];
        }

        $default = [
            'dimensions' => config('gtm.qualification.dimensions', []),
            'required_facts' => $required,
            'disqualifiers' => $disqualifiers,
            'confidence_bands' => [
                'low' => ['min' => 0, 'max' => 0.49],
                'medium' => ['min' => 0.5, 'max' => 0.74],
                'high' => ['min' => 0.75, 'max' => 1],
            ],
            'sensitive_predicates' => ['health', 'race', 'religion', 'politics', 'gender', 'age'],
            'decision_rules' => [
                'contact_min_confidence' => 0.75,
                'review_min_confidence' => 0.5,
                'missing_fact_outcome' => 'research_further',
            ],
        ];
        if ($definition === null) {
            return $default;
        }

        return array_replace($default, array_intersect_key($definition, $default));
    }

    /** @return array<string,mixed> */
    private function actorSnapshot(?User $actor): array
    {
        return $actor === null
            ? ['actor_type' => 'system', 'display_name' => 'System']
            : ['actor_type' => 'user', 'user_id' => (int) $actor->id, 'display_name' => mb_substr((string) $actor->name, 0, 160)];
    }
}
