<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGtmProfileWebsiteRequest extends FormRequest
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
            'website_url' => ['nullable', 'string', 'max:2048'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'client_operation_id' => ['required', 'string', 'max:128'],
        ];
    }
}
