<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Account;
use Illuminate\Http\Request;

class AccountResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $account = $this->model(Account::class);

        return [
            'id' => $account->id,
            'name' => $account->name,
            'website' => $account->website,
            'domain' => $account->domain_normalized,
            'notes' => $account->notes,
            'tags' => $account->tags ?? [],
            'source' => $account->source,
            'provenance' => $this->provenanceSummary($account),
            'people_count' => $account->getAttribute('people_count'),
            'created_at' => $this->iso8601($account->getAttribute('created_at')),
            'updated_at' => $this->iso8601($account->getAttribute('updated_at')),
        ];
    }
}
