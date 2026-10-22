<?php

namespace App\Domain\Mail\Enums;

enum MailExperimentStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Aborted = 'aborted';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Aborted], true);
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Ready, self::Aborted], true),
            self::Ready => in_array($target, [self::Running, self::Aborted], true),
            self::Running => in_array($target, [self::Paused, self::Completed, self::Aborted], true),
            self::Paused => in_array($target, [self::Running, self::Completed, self::Aborted], true),
            self::Completed, self::Aborted => false,
        };
    }
}
