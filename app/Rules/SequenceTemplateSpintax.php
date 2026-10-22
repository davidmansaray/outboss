<?php

namespace App\Rules;

use App\Domain\Campaigns\Services\SpintaxTemplate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class SequenceTemplateSpintax implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        try {
            app(SpintaxTemplate::class)->validate($value);
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
