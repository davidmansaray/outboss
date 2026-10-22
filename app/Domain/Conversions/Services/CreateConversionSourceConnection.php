<?php

namespace App\Domain\Conversions\Services;

use App\Models\ConversionSourceConnection;
use App\Models\User;
use App\Models\Workspace;

final class CreateConversionSourceConnection
{
    public function __construct(private readonly ConversionSourceConnectionManager $manager) {}

    /** @param array<string,mixed> $options */
    public function handle(Workspace $workspace, User $actor, string $kind, string $secret, array $options = []): ConversionSourceConnection
    {
        return $this->manager->connect($workspace, $kind, $secret, $actor, $options);
    }

    /** @param array<string,mixed> $options */
    public function create(Workspace $workspace, User $actor, string $kind, string $secret, array $options = []): ConversionSourceConnection
    {
        return $this->handle($workspace, $actor, $kind, $secret, $options);
    }
}
