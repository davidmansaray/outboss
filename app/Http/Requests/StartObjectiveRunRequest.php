<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Validate an owner request to generate a plan from one frozen experiment. */
final class StartObjectiveRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->currentWorkspace()->isOwner($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'gtm_experiment_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ];
    }
}
