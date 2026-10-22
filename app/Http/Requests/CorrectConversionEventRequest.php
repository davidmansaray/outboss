<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorrectConversionEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'external_event_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'occurred_at' => ['sometimes', 'nullable', 'date'],
            'properties' => ['sometimes', 'array', 'max:100'],
            'identities' => ['sometimes', 'array', 'max:10'],
            'identities.*' => ['required', 'array', 'max:10'],
            'value' => ['sometimes', 'nullable', 'numeric'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'idempotency_key' => ['sometimes', 'string', 'max:160'],
        ];
    }
}
