<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use Illuminate\Validation\ValidationException;
use RuntimeException;

trait MapsDomainExceptions
{
    protected function sequenceEditorMessage(RuntimeException $exception): string
    {
        return match ($exception->getMessage()) {
            'campaign_not_editable' => 'Pause the campaign before editing the sequence.',
            'sequence_stale' => 'The sequence changed. Reload and try again.',
            'step_immutable' => 'Published steps cannot be edited.',
            default => 'The sequence could not be updated.',
        };
    }

    protected function failSequence(RuntimeException $exception): never
    {
        throw ValidationException::withMessages([
            'sequence' => $this->sequenceEditorMessage($exception),
        ]);
    }

    protected function failSenderIdentity(string $field = 'mailbox_id'): never
    {
        throw ValidationException::withMessages([
            $field => SenderIdentityUnavailable::USER_MESSAGE,
        ]);
    }
}
