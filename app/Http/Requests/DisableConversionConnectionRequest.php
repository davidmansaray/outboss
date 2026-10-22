<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DisableConversionConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'expected_lock_version' => ['sometimes', 'integer', 'min:0'],
            'idempotency_key' => ['sometimes', 'string', 'max:160'],
        ];
    }
}
