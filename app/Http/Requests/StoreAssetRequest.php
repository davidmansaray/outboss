<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'external_url' => ['required', 'url:https', 'max:2048'],
            'media_type' => ['required', 'string', 'max:120'],
            'source' => ['sometimes', 'nullable', 'string', 'max:160'],
            'approval_state' => ['sometimes', 'string', 'in:draft,approved'],
            'availability_state' => ['sometimes', 'string', 'in:available,unavailable'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'classification' => ['sometimes', 'string', 'in:public,internal,restricted'],
            'access_instructions' => ['sometimes', 'array', 'max:20'],
            'intended_audience' => ['sometimes', 'array', 'max:20'],
            'delivery_restrictions' => ['sometimes', 'array', 'max:20'],
            'tracking_metadata' => ['sometimes', 'array', 'max:20'],
            'client_operation_id' => ['sometimes', 'nullable', 'string', 'max:160'],
            'operation_id' => ['sometimes', 'nullable', 'string', 'max:160'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:160'],
        ];
    }
}
