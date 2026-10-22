<?php

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Models\AiProviderCredential;
use App\Models\Campaign;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\S2BrowserSeeder;

it('seeds an S2 browser workspace without outreach records', function () {
    config()->set('intelligence.testing.fake_providers', true);

    $this->seed(S2BrowserSeeder::class);

    $owner = User::query()->where('email', S2BrowserSeeder::USER_EMAIL)->firstOrFail();
    expect(AiProviderCredential::query()->where('workspace_id', $owner->currentWorkspace()->id)->where('status', AiCredentialStatus::Healthy)->count())->toBe(2)
        ->and(Campaign::query()->where('workspace_id', $owner->currentWorkspace()->id)->count())->toBe(0)
        ->and(Message::query()->where('workspace_id', $owner->currentWorkspace()->id)->count())->toBe(0);
});

it('refuses to seed the deterministic S2 browser fixture without its explicit flag', function () {
    config()->set('intelligence.testing.fake_providers', false);

    expect(fn () => $this->seed(S2BrowserSeeder::class))
        ->toThrow(RuntimeException::class);
});
