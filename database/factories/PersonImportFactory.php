<?php

namespace Database\Factories;

use App\Domain\Campaigns\Enums\ImportStatus;
use App\Models\PersonImport;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PersonImport>
 */
class PersonImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'original_filename' => 'people.csv',
            'source_hash' => hash('sha256', Str::random(20)),
            'headers' => ['email', 'name', 'company'],
            'mapping' => ['email' => 'email', 'name' => 'name', 'company' => 'company'],
            'mapping_version' => 1,
            'status' => ImportStatus::Previewed,
            'expires_at' => now()->addDay(),
        ];
    }
}
