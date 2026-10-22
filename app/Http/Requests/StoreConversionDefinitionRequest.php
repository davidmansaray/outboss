<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreConversionDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'event_key' => ['sometimes', 'string', 'max:100'],
            'key' => ['sometimes', 'string', 'max:100'],
            'display_name' => ['sometimes', 'string', 'max:160'],
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'subject_type' => ['sometimes', 'string', 'in:Account,Person,Conversation,Objective'],
            'subject' => ['sometimes', 'string', 'in:Account,Person,Conversation,Objective'],
            'allowed_sources' => ['sometimes', 'array', 'max:20'],
            'allowed_sources.*' => ['string', 'in:manual,api,webhook,email_reply'],
            'source_types' => ['sometimes', 'array', 'max:20'],
            'source_types.*' => ['string', 'in:manual,api,webhook,email_reply'],
            'allowed_identity_keys' => ['sometimes', 'array', 'max:20'],
            'allowed_identity_keys.*' => ['string', 'in:account_id,external_identity,domain'],
            'identity_keys' => ['sometimes', 'array', 'max:20'],
            'identity_keys.*' => ['string', 'in:account_id,external_identity,domain'],
            'property_schema' => ['sometimes', 'array', 'max:10'],
            'properties_schema' => ['sometimes', 'array', 'max:10'],
            'dedupe_strategy' => ['sometimes', 'string', 'in:external_id,source_and_external_id,payload_hash,idempotency_key'],
            'dedupe_configuration' => ['sometimes', 'array', 'max:20'],
            'dedupe' => ['sometimes', 'array', 'max:20'],
            'attribution_window' => ['sometimes'],
            'attribution_window.days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'value_policy' => ['sometimes'],
            'value_policy.allowed' => ['sometimes', 'boolean'],
            'value_policy.currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'correction_policy' => ['sometimes', 'array', 'max:5'],
            'reversal_policy' => ['sometimes', 'array', 'max:5'],
            'retention_policy' => ['sometimes', 'array', 'max:5'],
            'retention_policy.unmatched_identity_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'export_policy' => ['sometimes', 'array', 'max:5'],
            'idempotency_key' => ['sometimes', 'string', 'max:160'],
        ];
    }
}
