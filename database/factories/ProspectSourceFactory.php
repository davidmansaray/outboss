<?php

namespace Database\Factories;

use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Enums\ProspectSourceStatus;
use App\Models\ProspectSource;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProspectSource> */
class ProspectSourceFactory extends Factory
{
    public function definition(): array
    {
        $generatedName = fake()->unique()->words(2, true);
        $name = is_array($generatedName) ? implode(' ', $generatedName) : $generatedName;

        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory(),
            'current_version_id' => null,
            'last_run_id' => null,
            'status' => ProspectSourceStatus::Active,
            'kind' => ProspectSourceKind::Manual,
            'system_key' => null,
            'display_name' => $name,
            'name_normalized' => mb_strtolower(trim($name)),
            'active_name_normalized' => mb_strtolower(trim($name)),
            'owner_user_id' => null,
            'lock_version' => 0,
            'deletion_requested_at' => null,
            'deletion_approved_version_id' => null,
            'deletion_approved_policy_hash' => null,
            'deletion_approved_behavior' => null,
            'deletion_delete_canonical' => false,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'status' => ProspectSourceStatus::Archived,
            'active_name_normalized' => null,
        ]);
    }

    public function system(string $key = 'manual'): static
    {
        return $this->state(fn (): array => [
            'system_key' => $key,
            'display_name' => ucfirst(str_replace('_', ' ', $key)),
            'name_normalized' => mb_strtolower(str_replace('_', ' ', $key)),
            'active_name_normalized' => mb_strtolower(str_replace('_', ' ', $key)),
        ]);
    }
}
