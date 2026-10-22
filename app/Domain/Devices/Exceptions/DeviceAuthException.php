<?php

namespace App\Domain\Devices\Exceptions;

use RuntimeException;

/**
 * A sign-in or session failure the iOS app acts on by its error type.
 */
class DeviceAuthException extends RuntimeException
{
    public function __construct(public readonly string $type, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function invalidGrant(): self
    {
        return new self('invalid_grant', 'This sign-in code or refresh token is invalid or has expired. Sign in again.', 400);
    }

    public static function sessionRevoked(): self
    {
        return new self('session_revoked', 'This device was signed out. Sign in again to continue.', 401);
    }

    public static function workspaceForbidden(): self
    {
        return new self('workspace_forbidden', 'You are not a member of that workspace.', 403);
    }

    public static function deviceSessionRequired(): self
    {
        return new self('device_session_required', 'This endpoint is only available to signed-in devices. Revoke API keys in Settings.', 403);
    }

    public static function syncResetRequired(): self
    {
        return new self('sync_reset_required', 'This sync position is too old or invalid. Reload the inbox from the first page.', 410);
    }
}
