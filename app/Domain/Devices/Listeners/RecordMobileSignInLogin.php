<?php

namespace App\Domain\Devices\Listeners;

use App\Domain\Devices\Support\MobileSignInLogin;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Notes a web login (password, two-factor or passkey) that completes a
 * pending iOS sign-in, so /mobile/authorize can skip the workspace page.
 */
class RecordMobileSignInLogin
{
    public function __construct(
        private readonly Request $request,
        private readonly MobileSignInLogin $logins,
    ) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! $this->request->hasSession()) {
            return;
        }

        $this->logins->loggedIn($this->request->session(), (int) $event->user->getAuthIdentifier());
    }
}
