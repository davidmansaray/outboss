<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RotateConversionConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'secret' => ['sometimes', 'string', 'max:4096'],
            'display_name' => ['sometimes', 'string', 'max:160'],
            'allowed_conversion_definition_ids' => ['sometimes', 'array', 'max:100'],
            'allowed_conversion_definition_ids.*' => ['string', 'uuid'],
            'definition_ids' => ['sometimes', 'array', 'max:100'],
            'definition_ids.*' => ['string', 'uuid'],
            'allowed_source_types' => ['sometimes', 'array', 'max:4'],
            'allowed_source_types.*' => ['string', 'in:manual,api,webhook,email_reply'],
            'source_types' => ['sometimes', 'array', 'max:4'],
            'source_types.*' => ['string', 'in:manual,api,webhook,email_reply'],
            'signature_algorithm' => ['sometimes', 'string', 'in:hmac_sha256'],
            'payload_limit_bytes' => ['sometimes', 'integer', 'min:1024', 'max:5242880'],
            'replay_window_seconds' => ['sometimes', 'integer', 'min:30', 'max:3600'],
            'rotation_overlap_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
            'ip_policy' => ['sometimes', 'nullable', 'array', 'max:20'],
            'rate_policy' => ['sometimes', 'nullable', 'array', 'max:20'],
            'expected_lock_version' => ['sometimes', 'integer', 'min:0'],
            'idempotency_key' => ['sometimes', 'string', 'max:160'],
        ];
    }
}
