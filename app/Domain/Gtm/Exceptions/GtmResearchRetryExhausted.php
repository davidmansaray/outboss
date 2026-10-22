<?php

namespace App\Domain\Gtm\Exceptions;

use RuntimeException;

final class GtmResearchRetryExhausted extends RuntimeException
{
    // A fresh logical run is required before more provider work is allowed.
}
