<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

final class StoreManagedMailDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        // EnsureManagedMailCapability is the route-level gate. Keeping this
        // request authorisable allows the same validation object to be used
        // by tests without weakening the middleware boundary.
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253'],
            'managed_instance_key' => [
                'nullable',
                'string',
                'max:80',
                'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,79}\z/',
            ],
        ];
    }
}
