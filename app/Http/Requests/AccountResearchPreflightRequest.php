<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AccountResearchPreflightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'gtm_profile_version_id' => ['sometimes', 'nullable', 'uuid'],
            'offer_version_id' => ['sometimes', 'nullable', 'uuid'],
            'freshness_policy' => ['sometimes', 'array', 'max:10'],
            'freshness_policy.snapshot_max_age_hours' => ['sometimes', 'integer', 'min:1', 'max:8760'],
            'freshness_policy.claim_max_age_hours' => ['sometimes', 'integer', 'min:1', 'max:8760'],
            'capabilities' => ['sometimes', 'array', 'max:3'],
            'capabilities.*' => ['string', 'max:64'],
            'maximum_cost_micros' => ['sometimes', 'integer', 'min:1', 'max:9223372036854775807'],
        ];
    }
}
