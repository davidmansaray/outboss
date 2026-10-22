<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewOfferVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:submit,approve,reject,reopen'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
