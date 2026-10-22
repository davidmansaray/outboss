<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiTaskRequest extends FormRequest
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
            'model_id' => ['required', 'string', 'max:120'],
            'per_run_limit_usd' => ['required', 'string', 'max:20', 'regex:/^\d+(?:\.\d{1,6})?$/'],
        ];
    }
}
