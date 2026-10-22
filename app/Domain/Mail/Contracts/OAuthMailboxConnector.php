<?php

namespace App\Domain\Mail\Contracts;

use App\Domain\Mail\DTOs\ConnectedAccount;
use App\Domain\Mail\DTOs\OAuthCallback;
use App\Domain\Mail\DTOs\RefreshedTokens;
use App\Models\OAuthAttempt;
use App\Models\ProviderConnection;

/** OAuth connection lifecycle for public bring-your-own-inbox providers. */
interface OAuthMailboxConnector
{
    public function authorizationUrl(OAuthAttempt $attempt): string;

    public function exchangeAuthorizationCode(OAuthCallback $callback): ConnectedAccount;

    public function refreshConnection(ProviderConnection $connection): RefreshedTokens;

    public function revokeConnection(ProviderConnection $connection): void;
}
