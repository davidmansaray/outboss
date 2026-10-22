<?php

namespace App\Domain\Conversions\Enums;

enum ConversionSubjectType: string
{
    case Account = 'Account';
    case Person = 'Person';
    case Conversation = 'Conversation';
    case Objective = 'Objective';
}
