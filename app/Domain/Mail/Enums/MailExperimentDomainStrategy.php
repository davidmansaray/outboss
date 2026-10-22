<?php

namespace App\Domain\Mail\Enums;

enum MailExperimentDomainStrategy: string
{
    case Subdomain = 'subdomain';
    case SeparateRootDomains = 'separate_root_domains';
}
