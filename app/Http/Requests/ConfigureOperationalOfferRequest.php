<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfigureOperationalOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'payload' => ['sometimes', 'array', 'max:30'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'client_operation_id' => ['sometimes', 'nullable', 'string', 'max:160'],
            'operation_id' => ['sometimes', 'nullable', 'string', 'max:160'],
        ];
    }
}
