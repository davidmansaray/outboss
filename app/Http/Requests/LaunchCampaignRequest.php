<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LaunchCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        $method = $this->route()?->getActionMethod();
        $ability = match ($method) {
            'launch' => 'launch',
            'resume' => 'resume',
            default => 'update',
        };

        return $this->user()?->can($ability, $this->route('campaign')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
