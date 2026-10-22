<?php

namespace App\Domain\Objectives\Enums;

enum ExecutionOrigin: string
{
    case Ai = 'ai';
    case Human = 'human';
}
