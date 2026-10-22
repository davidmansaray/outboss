<?php

namespace App\Domain\Conversions\Services;

use App\Models\ConversionSourceConnection;
use App\Models\User;

final class RotateConversionSourceConnection
{
    public function __construct(private readonly ConversionSourceConnectionManager $manager) {}

    /** @param array<string,mixed> $options */
    public function handle(ConversionSourceConnection $connection, User $actor, string $secret, array $options = []): ConversionSourceConnection
    {
        return $this->manager->rotate($connection, $secret, $actor, $options);
    }

    /** @param array<string,mixed> $options */
    public function rotate(ConversionSourceConnection $connection, User $actor, string $secret, array $options = []): ConversionSourceConnection
    {
        return $this->handle($connection, $actor, $secret, $options);
    }
}
