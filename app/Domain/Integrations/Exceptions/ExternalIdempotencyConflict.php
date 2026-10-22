<?php

namespace App\Domain\Integrations\Exceptions;

use RuntimeException;

final class ExternalIdempotencyConflict extends RuntimeException {}
