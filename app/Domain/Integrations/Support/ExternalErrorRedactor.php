<?php

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\DTOs\SafeExternalError;
use Throwable;

final class ExternalErrorRedactor
{
    /** @var list<string> */
    private const SECRET_PATTERNS = [
        '/(?:bearer|token|api[_-]?key|secret|password)\\s*[:=]\\s*[^\\s,;]+/i',
        '/\\b(?:sk|tvly|bd)_[A-Za-z0-9_-]+\\b/',
    ];

    public function fromThrowable(Throwable $exception, string $code = 'provider_failure', bool $ambiguous = false): SafeExternalError
    {
        $message = trim((string) $exception->getMessage());
        $message = preg_replace(self::SECRET_PATTERNS, '[redacted]', $message) ?: 'The external provider returned an error.';
        $message = mb_substr($message, 0, 500);

        return new SafeExternalError($code, $message, $ambiguous);
    }
}
