<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveGtmProfileReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->currentWorkspace()->isOwner($user);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                Rule::in(['accept_research', 'keep_owner', 'edit_owner', 'maintain', 'let_outboss_maintain']),
            ],
            'value' => ['nullable'],
            'edited_value' => ['nullable', 'array'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'client_operation_id' => ['required', 'string', 'max:128'],
        ];
    }
}
