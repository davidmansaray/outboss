<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiProviderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->currentWorkspace()->isOwner($user);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean']];
    }
}
