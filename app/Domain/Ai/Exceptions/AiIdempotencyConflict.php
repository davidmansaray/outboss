<?php

namespace App\Domain\Ai\Exceptions;

use RuntimeException;

class AiIdempotencyConflict extends RuntimeException {}
