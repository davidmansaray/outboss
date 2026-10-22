<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewPersonImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $workspace = $user->currentWorkspace();

        return $workspace->isOwner($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1'],
            'mapping' => ['required', 'array'],
            'mapping.email' => ['required', 'string'],
            'filename' => ['nullable', 'string', 'max:255'],
        ];
    }
}
