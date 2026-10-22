<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveExecutionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->currentWorkspace()->isOwner($user);
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'plan_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'display_manifest_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'action_fingerprints' => ['required', 'array', 'min:1', 'max:100'],
            'action_fingerprints.*' => ['required', 'string', 'distinct', 'regex:/\A[a-f0-9]{64}\z/'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
