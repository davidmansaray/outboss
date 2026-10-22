<?php

namespace App\Http\Requests;

use App\Models\Mailbox;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMailboxReplyIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $mailbox = $this->route('mailbox');

        return $mailbox instanceof Mailbox
            && ($this->user()?->can('update', $mailbox) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'identity_key' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }
}
