<?php

namespace App\Domain\Mail\Enums;

enum ThreadLocalState: string
{
    case NeedsAction = 'needs_action';
    case Open = 'open';
    case OutOfOffice = 'out_of_office';
    case Problem = 'problem';
    case Done = 'done';

    public static function fromFolder(string $folder): ?self
    {
        return match ($folder) {
            'needs' => self::NeedsAction,
            'open' => self::Open,
            'ooo' => self::OutOfOffice,
            'problems' => self::Problem,
            'done' => self::Done,
            default => null,
        };
    }

    public function folder(): string
    {
        return match ($this) {
            self::NeedsAction => 'needs',
            self::Open => 'open',
            self::OutOfOffice => 'ooo',
            self::Problem => 'problems',
            self::Done => 'done',
        };
    }
}
