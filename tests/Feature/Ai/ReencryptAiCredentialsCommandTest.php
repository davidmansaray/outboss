<?php

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Models\AiProviderCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('re-encrypts stored credentials without exposing their plaintext', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $secret = 'sk-or-command-private-value';
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => 'llm',
        'provider' => AiProviderName::OpenRouter,
        'status' => AiCredentialStatus::Healthy,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => $secret,
        'status' => 'active',
    ]);
    $before = DB::table('ai_provider_credential_versions')->where('id', $version->id)->value('encrypted_secret');

    $this->artisan('ai:reencrypt-credentials', ['--batch' => 1])
        ->expectsOutputToContain('Re-encrypted 1 credential version(s); 0 failed.')
        ->doesntExpectOutputToContain($secret)
        ->assertSuccessful();

    $after = DB::table('ai_provider_credential_versions')->where('id', $version->id)->value('encrypted_secret');
    expect($after)->not->toBe($before)
        ->and($version->fresh()->encrypted_secret)->toBe($secret);
});

it('fails closed and marks a credential unhealthy when ciphertext cannot decrypt', function () {
    $user = User::factory()->create();
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'capability' => 'research',
        'provider' => AiProviderName::Tavily,
        'status' => AiCredentialStatus::Healthy,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'tvly-private-value',
        'status' => 'active',
    ]);
    DB::table('ai_provider_credential_versions')->where('id', $version->id)->update(['encrypted_secret' => 'corrupt-ciphertext']);

    $this->artisan('ai:reencrypt-credentials')
        ->expectsOutputToContain('Re-encrypted 0 credential version(s); 1 failed.')
        ->assertFailed();

    expect($credential->fresh()->status)->toBe(AiCredentialStatus::Misconfigured)
        ->and($credential->fresh()->last_error_code)->toBe('credential_decrypt_failed');
});
