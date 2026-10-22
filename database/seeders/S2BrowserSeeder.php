<?php

namespace Database\Seeders;

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Providers\DeterministicOpenRouterAdapter;
use App\Domain\Ai\Providers\DeterministicTavilyAdapter;
use App\Models\AiProviderCredential;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Seed a zero-outreach workspace for the deterministic S2 browser proof. */
final class S2BrowserSeeder extends Seeder
{
    public const USER_EMAIL = 's2-browser@outboss.test';

    public function run(): void
    {
        if (! app()->environment('testing') || ! config('intelligence.testing.fake_providers')) {
            throw new \RuntimeException('The S2 browser fixture can only run in the explicit testing environment.');
        }

        $owner = User::query()->where('email', self::USER_EMAIL)->first();
        if ($owner === null) {
            $owner = User::factory()->create([
                'name' => 'S2 Browser Owner',
                'email' => self::USER_EMAIL,
                'password' => 'password',
                'timezone' => 'UTC',
                'email_verified_at' => now(),
            ]);
        }
        $this->credential($owner, AiProviderName::Tavily, DeterministicTavilyAdapter::FIXTURE_ID);
        $this->credential($owner, AiProviderName::OpenRouter, DeterministicOpenRouterAdapter::FIXTURE_ID);
    }

    private function credential(User $owner, AiProviderName $provider, string $fixtureId): void
    {
        $workspace = $owner->currentWorkspace();
        $credential = AiProviderCredential::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'capability' => $provider->capability(),
            ],
            [
                'provider' => $provider,
                'status' => AiCredentialStatus::Healthy,
                'created_by_user_id' => $owner->id,
                'updated_by_user_id' => $owner->id,
            ],
        );
        $version = $credential->versions()->firstOrCreate(
            ['version' => 1],
            [
                'encrypted_secret' => $fixtureId,
                'status' => 'active',
                'created_by_user_id' => $owner->id,
                'tested_at' => now(),
                'activated_at' => now(),
            ],
        );
        $credential->forceFill([
            'provider' => $provider,
            'status' => AiCredentialStatus::Healthy,
            'active_version_id' => $version->id,
            'last_tested_at' => now(),
            'last_healthy_at' => now(),
            'provider_balance_summary' => 'Deterministic browser fixture',
            'updated_by_user_id' => $owner->id,
        ])->save();
    }
}
