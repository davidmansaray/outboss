<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** Require an explicit server-side acknowledgement before remote deletion. */
final class ConfirmManagedMailboxDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // ManagedMailCapability middleware and the controller's scoped lookup
        // enforce authorization before the deletion service is reached.
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
        ];
    }
}
