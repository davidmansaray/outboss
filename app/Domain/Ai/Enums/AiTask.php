<?php

namespace App\Domain\Ai\Enums;

enum AiTask: string
{
    case AccountResearch = 'account_research';
    case Qualification = 'qualification';
    case MessageDrafting = 'message_drafting';
    case ConnectionTest = 'connection_test';

    /** @return list<string> */
    public static function productValues(): array
    {
        return [
            self::AccountResearch->value,
            self::Qualification->value,
            self::MessageDrafting->value,
        ];
    }
}
