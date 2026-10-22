<?php

namespace App\Http\Middleware;

use App\Domain\Devices\Support\MobileAuthorizationRequest;
use App\Domain\Devices\Support\MobileSignInLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before `auth` on GET /mobile/authorize. When a guest is about to be sent
 * to the login page, it records which sign-in attempt that login is for.
 */
class RememberMobileSignInRedirect
{
    public function __construct(private readonly MobileSignInLogin $logins) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null && $request->hasSession()) {
            $parameters = MobileAuthorizationRequest::parameters($request->query());

            if ($parameters !== null) {
                $this->logins->loginRequested($request->session(), $parameters);
            }
        }

        return $next($request);
    }
}
