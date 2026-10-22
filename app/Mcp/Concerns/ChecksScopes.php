<?php

namespace App\Mcp\Concerns;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Sanctum\Contracts\HasAbilities;

trait ChecksScopes
{
    protected function denyUnlessScoped(Request $request, string $scope): ?Response
    {
        $token = $this->accessToken($this->actor($request));

        if ($token !== null && ! $token->can($scope)) {
            return Response::error("Missing required scope: {$scope}");
        }

        return null;
    }

    protected function accessToken(User $user): ?HasAbilities
    {
        return $user->currentAccessToken();
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Authentication required.');
        }

        return $user;
    }
}
