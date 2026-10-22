<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Safe aggregate projection for one Account; it contains no Account notes. */
class AccountIntelligenceResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'account' => is_array($payload['account'] ?? null) ? $payload['account'] : [],
            'intelligence' => is_array($payload['intelligence'] ?? null) ? $payload['intelligence'] : [],
            'latest_run' => $payload['latest_run'] ?? null,
            'runs' => is_array($payload['runs'] ?? null) ? $payload['runs'] : [],
            'latest_qualification' => $payload['latest_qualification'] ?? null,
        ];
    }
}
