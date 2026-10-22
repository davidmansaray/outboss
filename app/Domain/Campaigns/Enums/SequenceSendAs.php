<?php

namespace App\Domain\Campaigns\Enums;

enum SequenceSendAs: string
{
    case NewThread = 'new_thread';
    case Reply = 'reply';
}
