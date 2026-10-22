<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCampaignRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('recipient')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['nullable', 'in:pause,resume'],
            'mailbox_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasAction = $this->filled('action');
            $hasMailbox = $this->filled('mailbox_id');

            if ($hasAction === $hasMailbox) {
                $validator->errors()->add('action', 'Provide exactly one of action or mailbox_id.');
            }
        });
    }
}
