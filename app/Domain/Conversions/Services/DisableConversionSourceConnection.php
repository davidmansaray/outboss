<?php

namespace App\Domain\Conversions\Services;

use App\Models\ConversionSourceConnection;
use App\Models\User;

final class DisableConversionSourceConnection
{
    public function __construct(private readonly ConversionSourceConnectionManager $manager) {}

    public function handle(ConversionSourceConnection $connection, User $actor): ConversionSourceConnection
    {
        return $this->manager->disable($connection, $actor);
    }

    public function disable(ConversionSourceConnection $connection, User $actor): ConversionSourceConnection
    {
        return $this->handle($connection, $actor);
    }
}
