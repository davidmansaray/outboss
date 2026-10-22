<?php

namespace App\Domain\Mail\Services;

use App\Models\ProviderConnection;

/** @deprecated Use EnsureConnectionUsable. */
class EnsureFreshToken
{
    public function __construct(private EnsureConnectionUsable $connections) {}

    public function handle(ProviderConnection $connection): ProviderConnection
    {
        return $this->connections->handle($connection);
    }
}
