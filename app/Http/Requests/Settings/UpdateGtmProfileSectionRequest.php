<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGtmProfileSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->currentWorkspace()->isOwner($user);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'payload' => ['required', 'array'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'client_operation_id' => ['required', 'string', 'max:128'],
        ];
    }
}
