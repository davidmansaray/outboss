<?php

namespace App\Domain\Objectives\Exceptions;

use RuntimeException;

final class MessageDraftingEvaluationException extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
