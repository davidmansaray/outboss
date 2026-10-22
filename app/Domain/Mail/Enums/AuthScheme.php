<?php

namespace App\Domain\Mail\Enums;

enum AuthScheme: string
{
    case OAuth2 = 'oauth2';
    case AppPassword = 'app_password';
}
