<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TestSequenceStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('step')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mailbox_id' => ['required', 'integer'],
            'to' => ['required', 'email'],
            'client_operation_id' => ['required', 'uuid'],
        ];
    }
}
