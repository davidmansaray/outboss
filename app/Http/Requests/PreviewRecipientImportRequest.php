<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewRecipientImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('update', $this->route('campaign'))
            && $user->currentWorkspace()->isOwner($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', 'in:paste,csv,sample'],
            'rows' => [
                'required_unless:source,sample',
                'array',
                'min:1',
                'max:10000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_array($value) && strlen((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 5 * 1024 * 1024) {
                        $fail('Parsed CSV data must be 5 MB or smaller.');
                    }
                },
            ],
            'rows.*' => ['array', 'max:100'],
            'rows.*.*' => ['nullable', 'string', 'max:10000'],
            'mapping' => ['required_unless:source,sample', 'array'],
            'mapping.email' => ['required_unless:source,sample', 'string', 'max:255'],
            'mapping.name' => ['nullable', 'string', 'max:255'],
            'mapping.first_name' => ['nullable', 'string', 'max:255'],
            'mapping.company' => ['nullable', 'string', 'max:255'],
            'filename' => ['nullable', 'string', 'max:255'],
        ];
    }
}
