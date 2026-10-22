<?php

namespace App\Http\Requests;

use App\Rules\SequenceTemplateSpintax;
use App\Rules\SequenceTemplateVariables;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSequenceStepRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:80'],
            'delay_minutes' => ['sometimes', 'integer', 'in:0,2880,4320,5760,7200,10080'],
            'send_as' => ['sometimes', 'in:new_thread,reply'],
            'subject_spintax_enabled' => ['sometimes', 'boolean'],
            'body_spintax_enabled' => ['sometimes', 'boolean'],
            'subject_template' => ['nullable', 'string', 'max:240', new SequenceTemplateVariables, new SequenceTemplateSpintax],
            'body_template' => ['sometimes', 'string', 'max:10000', new SequenceTemplateVariables, new SequenceTemplateSpintax],
        ];
    }
}
