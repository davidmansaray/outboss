<?php

namespace App\Domain\Gtm\Support;

use LogicException;

trait ImmutableVersion
{
    protected static function bootImmutableVersion(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Version rows are immutable.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Version rows are immutable.');
        });
    }
}
