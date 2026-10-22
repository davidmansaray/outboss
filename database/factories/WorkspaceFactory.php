<?php

namespace Database\Factories;

use App\Domain\Workspaces\Services\ProvisionWorkspace;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Workspace $workspace): void {
            app(ProvisionWorkspace::class)->ensure($workspace);
        });
    }

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => 'personal',
        ];
    }
}
