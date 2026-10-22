<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

final class AttachMailExperimentMailboxesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'mailbox_ids' => ['required', 'array', 'min:1', 'max:100'],
            'mailbox_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ];
    }
}
