<?php

namespace App\Domain\Mail\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ClientOperationId
{
    public static function validate(string $value): string
    {
        $value = trim($value);

        if (strlen($value) > 36 || ! Str::isUuid($value)) {
            throw ValidationException::withMessages([
                'client_operation_id' => 'The client operation id must be a valid UUID.',
            ]);
        }

        return $value;
    }
}
