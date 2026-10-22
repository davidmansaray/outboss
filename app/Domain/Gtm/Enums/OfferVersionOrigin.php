<?php

namespace App\Domain\Gtm\Enums;

enum OfferVersionOrigin: string
{
    case Created = 'created';
    case OwnerEdit = 'owner_edit';
    case StatusChange = 'status_change';
}
