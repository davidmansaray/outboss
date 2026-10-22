<?php

namespace App\Domain\Mail\Exceptions;

use RuntimeException;

/**
 * Raised when a mutation would change inputs bound to a frozen experiment.
 *
 * This is a runtime exception so the campaign/sequence HTTP adapters can map
 * it to their existing validation response while domain callers can still
 * distinguish it from ordinary campaign editing failures.
 */
final class FrozenMailExperimentInputMutation extends RuntimeException {}
