<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->currentWorkspace()->isOwner($user);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:'.config('gtm.limits.identity_max_length', 160)],
            'promise' => ['sometimes', 'nullable', 'string', 'max:'.config('gtm.limits.assertion_max_length', 5000)],
            'target_audience' => ['sometimes', 'nullable', 'string', 'max:'.config('gtm.limits.assertion_max_length', 5000)],
            'desired_outcome' => ['sometimes', 'nullable', 'string', 'max:'.config('gtm.limits.assertion_max_length', 5000)],
            'call_to_action' => ['sometimes', 'nullable', 'string', 'max:'.config('gtm.limits.assertion_max_length', 5000)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:'.config('gtm.limits.notes_max_length', 2000)],
            'client_operation_id' => ['required', 'string', 'max:128'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->input('payload');
        if (is_array($payload)) {
            $this->merge($payload);
        }

        foreach (['name', 'promise', 'target_audience', 'desired_outcome', 'call_to_action', 'notes'] as $field) {
            if ($this->exists($field) && $this->input($field) === null) {
                $this->merge([$field => '']);
            }
        }
    }
}
