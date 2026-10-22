<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenScope
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->deny($scope);
        }

        if ($request->bearerToken() === null) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->can($scope)) {
            return $next($request);
        }

        return $this->deny($scope);
    }

    private function deny(string $scope): Response
    {
        return response()->json([
            'error' => [
                'type' => 'insufficient_scope',
                'message' => __('This API key does not have the :scope scope.', ['scope' => $scope]),
            ],
        ], 403);
    }
}
