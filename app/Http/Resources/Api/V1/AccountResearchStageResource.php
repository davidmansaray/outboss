<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AccountResearchStage;
use Illuminate\Http\Request;

class AccountResearchStageResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $stage = $this->model(AccountResearchStage::class);

        return [
            'id' => (string) $stage->id,
            'run_id' => (string) $stage->research_run_id,
            'stage_order' => (int) $stage->stage_order,
            'stage_key' => $this->enumValue($stage->getAttribute('stage_key')) ?? (string) $stage->getRawOriginal('stage_key'),
            'state' => $this->enumValue($stage->getAttribute('state')) ?? (string) $stage->getRawOriginal('state'),
            'entry_predicate' => is_array($stage->getAttribute('entry_predicate_snapshot')) ? $stage->getAttribute('entry_predicate_snapshot') : [],
            'missing_facts' => is_array($stage->getAttribute('missing_facts_snapshot')) ? $stage->getAttribute('missing_facts_snapshot') : [],
            'expected_cost_micros' => (int) $stage->expected_cost_micros,
            'maximum_cost_micros' => (int) $stage->maximum_cost_micros,
            'implementation_version' => (int) $stage->implementation_version,
            'checkpoint_hash' => $stage->checkpoint_hash,
            'safe_error_code' => $stage->safe_error_code,
            'fencing_token' => (int) $stage->fencing_token,
            'lease_expires_at' => $this->iso8601($stage->getAttribute('lease_expires_at')),
            'started_at' => $this->iso8601($stage->getAttribute('started_at')),
            'succeeded_at' => $this->iso8601($stage->getAttribute('succeeded_at')),
            'failed_at' => $this->iso8601($stage->getAttribute('failed_at')),
        ];
    }
}
