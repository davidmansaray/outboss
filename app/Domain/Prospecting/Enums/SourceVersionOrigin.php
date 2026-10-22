<?php

namespace App\Domain\Prospecting\Enums;

enum SourceVersionOrigin: string
{
    case User = 'user';
    case System = 'system';
    case Migration = 'migration';
    case Api = 'api';
    case Mcp = 'mcp';
}
