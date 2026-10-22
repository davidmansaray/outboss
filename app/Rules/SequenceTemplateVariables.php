<?php

namespace App\Rules;

use App\Domain\Campaigns\Services\RenderSequenceMessage;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SequenceTemplateVariables implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (! preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $value, $matches)) {
            return;
        }

        foreach ($matches[1] as $variable) {
            if (in_array($variable, RenderSequenceMessage::SUPPORTED_VARIABLES, true)) {
                continue;
            }

            $supported = collect(RenderSequenceMessage::SUPPORTED_VARIABLES)
                ->map(fn (string $name): string => '{{'.$name.'}}')
                ->implode(', ');

            $fail('Unknown variable {{'.$variable.'}}. Supported: '.$supported.'.');

            return;
        }
    }
}
