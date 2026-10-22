<?php

namespace App\Http\Requests;

use App\Domain\Gtm\Enums\OfferStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeOfferStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // See UpdateOfferRequest::authorize() for the scoped-404 rationale.
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(OfferStatus::class)],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'client_operation_id' => ['nullable', 'string', 'max:128'],
        ];
    }
}
