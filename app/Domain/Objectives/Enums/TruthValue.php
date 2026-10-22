<?php

namespace App\Domain\Objectives\Enums;

enum TruthValue: string
{
    case True = 'true';
    case False = 'false';
    case Unknown = 'unknown';

    public function negate(): self
    {
        return match ($this) {
            self::True => self::False,
            self::False => self::True,
            self::Unknown => self::Unknown,
        };
    }
}
