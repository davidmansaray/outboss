<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

final class StoreManagedMailboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'local_part' => [
                'required',
                'string',
                'max:64',
                'regex:/\A[a-zA-Z0-9][a-zA-Z0-9.!#$%&\'*+\/?^_`{|}~-]{0,63}\z/',
            ],
            'display_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
