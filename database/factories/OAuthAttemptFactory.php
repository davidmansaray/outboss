<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\OAuthIntent;
use App\Models\OAuthAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use LogicException;

/**
 * @extends Factory<OAuthAttempt>
 */
class OAuthAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => function (array $attributes): int {
                $userId = $attributes['user_id'] ?? null;

                if (! is_numeric($userId)) {
                    throw new LogicException('An OAuth attempt factory requires a persisted user.');
                }

                return User::query()
                    ->whereKey($userId)
                    ->firstOrFail()
                    ->currentWorkspace()
                    ->id;
            },
            'provider' => MailProviderName::Gmail,
            'state_hash' => hash('sha256', Str::random(40)),
            'pkce_verifier' => Str::random(64),
            'intent' => OAuthIntent::ConnectMailbox,
            'expires_at' => now()->addMinutes(10),
        ];
    }
}
