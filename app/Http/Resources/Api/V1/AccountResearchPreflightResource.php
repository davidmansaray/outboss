<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Intelligence\DTOs\AccountResearchPreflight;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/** Safe, bounded admission metadata for one Account research request. */
class AccountResearchPreflightResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        if (! $this->resource instanceof AccountResearchPreflight) {
            throw new RuntimeException('Unexpected Account research preflight resource.');
        }

        return $this->resource->toArray();
    }
}
