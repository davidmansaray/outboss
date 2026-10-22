<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvidence;
use Illuminate\Http\Request;

class ResearchClaimResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $claim = $this->model(ResearchClaim::class);
        $evidence = $claim->relationLoaded('evidence')
            ? $claim->evidence->map(fn (ResearchClaimEvidence $edge): array => [
                'id' => (string) $edge->id,
                'source_snapshot_id' => (string) $edge->source_snapshot_id,
                'edge_type' => $this->enumValue($edge->getAttribute('edge_type')) ?? (string) $edge->getRawOriginal('edge_type'),
                'locator' => $edge->locator,
                'locator_hash' => $edge->locator_hash,
                'quote_hash' => $edge->quote_hash,
                'evidence_weight' => $edge->evidence_weight === null ? null : (float) $edge->evidence_weight,
                'extraction_version' => $edge->extraction_version,
            ])->values()->all()
            : null;

        return [
            'id' => (string) $claim->id,
            'account_id' => (int) $claim->account_id,
            'run_id' => (string) $claim->research_run_id,
            'supersedes_claim_id' => $claim->supersedes_claim_id === null ? null : (string) $claim->supersedes_claim_id,
            'subject_type' => (string) $claim->subject_type,
            'predicate' => (string) $claim->predicate,
            'value_type' => (string) $claim->value_type,
            'value' => $claim->value_json,
            'value_hash' => (string) $claim->value_hash,
            'status' => $this->enumValue($claim->getAttribute('status')) ?? (string) $claim->getRawOriginal('status'),
            'confidence' => (float) $claim->confidence,
            'calibration_band' => $claim->calibration_band,
            'observed_at' => $this->iso8601($claim->getAttribute('observed_at')),
            'fresh_until' => $this->iso8601($claim->getAttribute('fresh_until')),
            'expires_at' => $this->iso8601($claim->getAttribute('expires_at')),
            'extractor_kind' => $claim->extractor_kind,
            'extractor_version' => $claim->extractor_version,
            'policy_version' => $claim->policy_version,
            'catalogue_version' => $claim->catalogue_version,
            'safe_for_message' => (bool) $claim->safe_for_message,
            'content_hash' => (string) $claim->content_hash,
            'evidence' => $evidence,
        ];
    }
}
