<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->currentWorkspace()->isOwner($user);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'monthly_limit_usd' => ['required', 'string', 'max:20', 'regex:/^\d+(?:\.\d{1,6})?$/'],
            'tavily_credit_price_usd' => ['required', 'string', 'max:20', 'regex:/^\d+(?:\.\d{1,6})?$/'],
        ];
    }
}
