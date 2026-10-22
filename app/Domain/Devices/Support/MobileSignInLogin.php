<?php

namespace App\Domain\Devices\Support;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Tracks whether a person signed in as part of one iOS sign-in attempt.
 *
 * The workspace page may only be skipped when the person typed their
 * credentials for this attempt: /mobile/authorize sent them to the login page,
 * and they signed in moments ago. Someone who already had a browser session
 * always sees the page, so another app on the phone cannot quietly collect a
 * code from that session.
 */
final class MobileSignInLogin
{
    private const SESSION_KEY = 'mobile_authorize.login';

    /** How long after signing in the page can still be skipped. */
    public const FRESH_LOGIN_SECONDS = 120;

    /** How long a login started from /mobile/authorize stays tied to it. */
    public const PENDING_LOGIN_SECONDS = 600;

    /**
     * Called when /mobile/authorize sends a guest to the login page.
     *
     * @param  array{client: string, redirect_uri: string, code_challenge: string, state: string}  $parameters
     */
    public function loginRequested(Session $session, array $parameters): void
    {
        $session->put(self::SESSION_KEY, [
            'fingerprint' => MobileAuthorizationRequest::fingerprint($parameters),
            'requested_at' => now()->getTimestamp(),
            'user_id' => null,
            'logged_in_at' => null,
        ]);
    }

    /**
     * Called on every web login. Only a login that is completing a pending
     * /mobile/authorize attempt is recorded.
     */
    public function loggedIn(Session $session, int $userId): void
    {
        $pending = $this->pending($session);

        if ($pending === null
            || $pending['logged_in_at'] !== null
            || now()->getTimestamp() - $pending['requested_at'] > self::PENDING_LOGIN_SECONDS
            || ! str_contains((string) $session->get('url.intended', ''), '/mobile/authorize')) {
            $session->forget(self::SESSION_KEY);

            return;
        }

        $session->put(self::SESSION_KEY, [
            ...$pending,
            'user_id' => $userId,
            'logged_in_at' => now()->getTimestamp(),
        ]);
    }

    /**
     * Whether this person signed in for exactly this attempt within the last
     * two minutes. The record is spent either way, so it allows one skip.
     *
     * @param  array{client: string, redirect_uri: string, code_challenge: string, state: string}  $parameters
     */
    public function pullFreshLogin(Session $session, User $user, array $parameters): bool
    {
        $pending = $this->pending($session);
        $session->forget(self::SESSION_KEY);

        if ($pending === null || $pending['logged_in_at'] === null) {
            return false;
        }

        $age = now()->getTimestamp() - $pending['logged_in_at'];

        return $pending['user_id'] === $user->id
            && $age >= 0
            && $age <= self::FRESH_LOGIN_SECONDS
            && hash_equals($pending['fingerprint'], MobileAuthorizationRequest::fingerprint($parameters));
    }

    /**
     * @return array{fingerprint: string, requested_at: int, user_id: int|null, logged_in_at: int|null}|null
     */
    private function pending(Session $session): ?array
    {
        $value = $session->get(self::SESSION_KEY);

        if (! is_array($value)
            || ! is_string($value['fingerprint'] ?? null)
            || ! is_int($value['requested_at'] ?? null)
            || ! (is_int($value['user_id'] ?? null) || ($value['user_id'] ?? null) === null)
            || ! (is_int($value['logged_in_at'] ?? null) || ($value['logged_in_at'] ?? null) === null)) {
            return null;
        }

        return [
            'fingerprint' => $value['fingerprint'],
            'requested_at' => $value['requested_at'],
            'user_id' => $value['user_id'],
            'logged_in_at' => $value['logged_in_at'],
        ];
    }
}
