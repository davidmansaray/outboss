<?php

namespace App\Domain\Conversions\Services;

use App\Models\ConversionDefinition;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/** Backward-compatible descriptive alias for workspace provisioning callers. */
final class CopyDefaultConversionDefinitions
{
    public function __construct(private readonly CopyConversionDefinitionTemplates $templates) {}

    /**
     * @param  list<string>|null  $templateKeys
     * @return Collection<int,ConversionDefinition>
     */
    public function handle(Workspace $workspace, ?User $actor = null, ?array $templateKeys = null): Collection
    {
        return $this->templates->handle($workspace, $actor, $templateKeys === null ? null : array_map('strval', $templateKeys));
    }

    /**
     * @param  list<string>|null  $templateKeys
     * @return Collection<int,ConversionDefinition>
     */
    public function copy(Workspace $workspace, ?User $actor = null, ?array $templateKeys = null): Collection
    {
        return $this->handle($workspace, $actor, $templateKeys === null ? null : array_map('strval', $templateKeys));
    }
}
