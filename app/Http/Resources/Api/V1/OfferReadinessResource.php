<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OfferReadinessEvaluation;
use Illuminate\Http\Request;

class OfferReadinessResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $evaluation = $this->model(OfferReadinessEvaluation::class);

        return [
            'id' => (string) $evaluation->id,
            'workspace_id' => (int) $evaluation->workspace_id,
            'offer_id' => (string) $evaluation->offer_id,
            'offer_version_id' => (string) $evaluation->offer_version_id,
            'evaluator_version' => (int) $evaluation->evaluator_version,
            'state' => $this->enumValue($evaluation->getAttribute('state')) ?? (string) $evaluation->getRawOriginal('state'),
            'blockers' => is_array($evaluation->getAttribute('blockers')) ? array_values(array_slice($evaluation->getAttribute('blockers'), 0, 100)) : [],
            'dependency_snapshot' => is_array($evaluation->getAttribute('dependency_snapshot')) ? $evaluation->getAttribute('dependency_snapshot') : [],
            'dependency_hash' => (string) $evaluation->dependency_hash,
            'trigger' => $evaluation->trigger,
            'evaluated_at' => $this->iso8601($evaluation->getAttribute('evaluated_at')),
            'valid_until' => $this->iso8601($evaluation->getAttribute('valid_until')),
            'created_at' => $this->iso8601($evaluation->getAttribute('created_at')),
        ];
    }
}
