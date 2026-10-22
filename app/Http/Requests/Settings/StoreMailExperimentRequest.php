<?php

namespace App\Http\Requests\Settings;

use App\Domain\Mail\Enums\MailExperimentDomainStrategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreMailExperimentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'hypothesis' => ['required', 'string', 'max:4000'],
            'randomisation_seed' => ['nullable', 'string', 'max:255'],
            'target_assignments_per_cohort' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'configuration' => ['nullable', 'array'],
            'cohorts' => ['nullable', 'array', 'size:2'],
            'cohorts.*.key' => ['required_with:cohorts', 'string', 'max:64', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}\z/'],
            'cohorts.*.label' => ['required_with:cohorts', 'string', 'max:255'],
            'cohorts.*.domain_strategy' => [
                'required_with:cohorts',
                'string',
                Rule::in(array_map(fn (MailExperimentDomainStrategy $strategy): string => $strategy->value, MailExperimentDomainStrategy::cases())),
            ],
            'cohorts.*.mailbox_ids' => ['nullable', 'array', 'max:100'],
            'cohorts.*.mailbox_ids.*' => ['integer', 'distinct'],
        ];
    }
}
