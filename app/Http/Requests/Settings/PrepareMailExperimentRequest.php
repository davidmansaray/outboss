<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

final class PrepareMailExperimentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'template_campaign_id' => ['nullable', 'integer', 'min:1'],
            'compliance_review_confirmed' => ['required', 'accepted'],
            'legal_review_confirmed' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'compliance_review_confirmed.accepted' => 'Confirm that the prepared audience passed the compliance review.',
            'legal_review_confirmed.accepted' => 'Confirm that the prepared audience passed the legal review.',
        ];
    }
}
