<?php

namespace App\Domain\Mail\Enums;

enum ConnectionStatus: string
{
    case Connected = 'connected';
    case NeedsReconnect = 'needs_reconnect';
    case Disconnected = 'disconnected';
}
