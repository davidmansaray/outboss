<?php

use App\Http\Middleware\EnsureManagedMailCapability;
use App\Http\Middleware\EnsureTokenScope;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RememberMobileSignInRedirect;
use App\Http\Middleware\ReplayIdempotentRequest;
use App\Http\Responses\ApiError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'scope' => EnsureTokenScope::class,
            'idempotency' => ReplayIdempotentRequest::class,
            'managed-mail' => EnsureManagedMailCapability::class,
        ]);

        // Must see the guest before `auth` redirects them to the login page.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: RememberMobileSignInRedirect::class,
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->validateCsrfTokens(except: [
            'unsub/*',
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($exception instanceof AuthenticationException) {
                return ApiError::forUnauthenticated($request);
            }

            return ApiError::fromException($exception);
        });
    })->create();
