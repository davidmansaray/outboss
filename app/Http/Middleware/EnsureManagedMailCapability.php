<?php

namespace App\Http\Middleware;

use App\Domain\Mail\Services\ManagedMailCapability;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Gate internal managed-mail routes before controllers can resolve records or
 * invoke a configured management client. Denials intentionally use 404 so an
 * unauthenticated user or non-owner cannot discover that the pilot exists.
 */
final class EnsureManagedMailCapability
{
    public function __construct(private readonly ManagedMailCapability $capability) {}

    public function handle(Request $request, Closure $next, string $capability = ManagedMailCapability::MANAGED_MAIL): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(404);
        }

        try {
            $workspace = $user->currentWorkspace();
        } catch (Throwable) {
            abort(404);
        }

        abort_unless($this->capability->allows($user, $workspace, $capability), 404);

        return $next($request);
    }
}
