<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SuppressionEntry;
use Illuminate\Http\Request;

class SuppressionEntryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->model(SuppressionEntry::class);

        return [
            'id' => $entry->id,
            'email' => $entry->email_address,
            'reason' => $this->enumValue($entry->getAttribute('reason')),
            'created_at' => $this->iso8601($entry->getAttribute('created_at')),
        ];
    }
}
