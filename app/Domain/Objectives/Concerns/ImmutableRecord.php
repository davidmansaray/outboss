<?php

namespace App\Domain\Objectives\Concerns;

use LogicException;

trait ImmutableRecord
{
    protected static function bootImmutableRecord(): void
    {
        static::updating(function (): never {
            throw new LogicException(class_basename(static::class).' records are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException(class_basename(static::class).' records are immutable.');
        });
    }
}
