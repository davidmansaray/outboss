<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ResearchSourceSnapshot;
use Illuminate\Http\Request;

/** Metadata-only source projection; raw provider/source content is omitted. */
class ResearchSourceSnapshotResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $snapshot = $this->model(ResearchSourceSnapshot::class);

        return [
            'id' => (string) $snapshot->id,
            'account_id' => (int) $snapshot->account_id,
            'run_id' => (string) $snapshot->research_run_id,
            'stage_id' => $snapshot->stage_id === null ? null : (string) $snapshot->stage_id,
            'source_type' => (string) $snapshot->source_type,
            'canonical_public_url' => $snapshot->canonical_public_url,
            'registrable_domain' => $snapshot->registrable_domain,
            'retrieved_at' => $this->iso8601($snapshot->getAttribute('retrieved_at')),
            'observed_at' => $this->iso8601($snapshot->getAttribute('observed_at')),
            'content_hash' => (string) $snapshot->content_hash,
            'mime_type' => $snapshot->mime_type,
            'byte_count' => (int) $snapshot->byte_count,
            'language' => $snapshot->language,
            'retrieval_capability' => $snapshot->retrieval_capability,
            'retrieval_version' => (int) $snapshot->retrieval_version,
            'provider_request_id' => $snapshot->provider_request_id,
            'provider_item_rank' => $snapshot->provider_item_rank === null ? null : (int) $snapshot->provider_item_rank,
            'retrieval_policy_hash' => $snapshot->retrieval_policy_hash,
            'display_classification' => $snapshot->display_classification,
            'llm_use_classification' => $snapshot->llm_use_classification,
            'export_classification' => $snapshot->export_classification,
            'retention_classification' => $snapshot->retention_classification,
            'expires_at' => $this->iso8601($snapshot->getAttribute('expires_at')),
            'redacted_at' => $this->iso8601($snapshot->getAttribute('redacted_at')),
            'state' => $this->enumValue($snapshot->getAttribute('state')) ?? (string) $snapshot->getRawOriginal('state'),
            'safe_failure_code' => $snapshot->safe_failure_code,
            'result_identity' => (string) $snapshot->result_identity,
            'evidence_count' => $snapshot->relationLoaded('evidence') ? $snapshot->evidence->count() : null,
        ];
    }
}
