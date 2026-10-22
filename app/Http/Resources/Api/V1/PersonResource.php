<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Account;
use App\Models\Person;
use Illuminate\Http\Request;

class PersonResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $person = $this->model(Person::class);
        $account = $person->account;

        return [
            'id' => $person->id,
            'full_name' => $person->full_name,
            'first_name' => $person->first_name,
            'email' => $person->email_address,
            'job_title' => $person->job_title,
            'notes' => $person->notes,
            'tags' => $person->tags ?? [],
            'source' => $person->source,
            'provenance' => $this->provenanceSummary($person),
            'account_id' => $person->account_id !== null ? (int) $person->account_id : null,
            'account' => $account instanceof Account ? [
                'id' => $account->id,
                'name' => $account->name,
            ] : null,
            'created_at' => $this->iso8601($person->getAttribute('created_at')),
            'updated_at' => $this->iso8601($person->getAttribute('updated_at')),
        ];
    }
}
