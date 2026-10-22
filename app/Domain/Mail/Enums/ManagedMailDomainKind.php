<?php

namespace App\Domain\Mail\Enums;

enum ManagedMailDomainKind: string
{
    case Subdomain = 'subdomain';
    case Root = 'root';
}
