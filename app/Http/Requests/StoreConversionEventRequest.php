<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreConversionEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'definition_id' => ['sometimes', 'uuid'],
            'conversion_definition_id' => ['sometimes', 'uuid'],
            'definition_version_id' => ['sometimes', 'uuid'],
            'conversion_definition_version_id' => ['sometimes', 'uuid'],
            'source' => ['sometimes', 'string', 'in:manual,api'],
            'source_type' => ['sometimes', 'string', 'in:manual,api'],
            'external_event_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'event_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'occurred_at' => ['sometimes', 'nullable', 'date'],
            'effective_at' => ['sometimes', 'nullable', 'date'],
            'properties' => ['sometimes', 'array', 'max:100'],
            'identities' => ['sometimes', 'array', 'max:10'],
            'identities.*' => ['required', 'array', 'max:10'],
            'identities.*.type' => ['sometimes', 'string', 'max:32'],
            'identities.*.value' => ['sometimes', 'string', 'max:1000'],
            'identities.*.provider' => ['sometimes', 'string', 'max:100'],
            'identities.*.identity_type' => ['sometimes', 'string', 'max:80'],
            'account_id' => ['sometimes', 'integer', 'min:1'],
            'domain' => ['sometimes', 'string', 'max:253'],
            'external_identity' => ['sometimes', 'array', 'max:10'],
            'value' => ['sometimes', 'nullable', 'numeric'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'idempotency_key' => ['sometimes', 'string', 'max:160'],
        ];
    }
}
