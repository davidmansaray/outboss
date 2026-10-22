<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AccountResearchAttempt;
use Illuminate\Http\Request;

class AccountResearchAttemptResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $attempt = $this->model(AccountResearchAttempt::class);

        return [
            'id' => (string) $attempt->id,
            'run_id' => (string) $attempt->research_run_id,
            'stage_id' => (string) $attempt->stage_id,
            'attempt_number' => (int) $attempt->attempt_number,
            'provider_operation_id' => $attempt->provider_operation_id === null ? null : (string) $attempt->provider_operation_id,
            'input_hash' => (string) $attempt->input_hash,
            'state' => $this->enumValue($attempt->getAttribute('state')) ?? (string) $attempt->getRawOriginal('state'),
            'safe_error_code' => $attempt->safe_error_code,
            'fencing_token' => (int) $attempt->fencing_token,
            'lease_expires_at' => $this->iso8601($attempt->getAttribute('lease_expires_at')),
            'started_at' => $this->iso8601($attempt->getAttribute('started_at')),
            'finished_at' => $this->iso8601($attempt->getAttribute('finished_at')),
            'has_outcome' => is_array($attempt->getAttribute('outcome')) && $attempt->getAttribute('outcome') !== [],
        ];
    }
}
