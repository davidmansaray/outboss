<?php

namespace App\Http\Requests;

use App\Domain\Mail\Enums\SuppressionReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSuppressionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'reason' => ['sometimes', Rule::enum(SuppressionReason::class)],
        ];
    }
}
