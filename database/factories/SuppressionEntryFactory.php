<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\SuppressionReason;
use App\Models\SuppressionEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SuppressionEntry>
 */
class SuppressionEntryFactory extends Factory
{
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'user_id' => User::factory(),
            'workspace_id' => fn (array $attributes) => User::query()
                ->whereKey($attributes['user_id'])
                ->firstOrFail()
                ->currentWorkspace()
                ->getKey(),
            'email_address' => $email,
            'email_normalized' => strtolower(trim($email)),
            'reason' => SuppressionReason::Manual,
        ];
    }
}
