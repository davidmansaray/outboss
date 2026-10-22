<?php

use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Services\EnsureFreshToken;
use App\Models\ProviderConnection;
use Illuminate\Support\Facades\Crypt;

it('detects secrets that cannot be decrypted under the current APP_KEY', function () {
    $connection = ProviderConnection::factory()->create();

    ProviderConnection::query()->whereKey($connection->id)->update([
        'access_token' => '1000.plaintext-access',
        'refresh_token' => '1000.plaintext-refresh',
        'provider_metadata' => json_encode([
            'accounts_server' => 'https://accounts.zoho.eu',
            'api_domain' => 'https://mail.zoho.eu',
        ]),
    ]);

    $connection = $connection->fresh();

    expect($connection->hasDecryptableSecrets())->toBeFalse();
});

it('detects properly encrypted secrets as decryptable', function () {
    $connection = ProviderConnection::factory()->create();

    expect($connection->hasDecryptableSecrets())->toBeTrue();
});

it('stores refreshed tokens through the encrypted cast instead of as plaintext', function () {
    FakeMailProvider::seedMailbox('david@outboss.test', MailProviderName::Gmail, 'fake-gmail');

    $connection = ProviderConnection::factory()->create([
        'provider' => MailProviderName::Gmail,
        'provider_user_id' => 'fake-gmail',
        'token_expires_at' => now()->subMinute(),
        'token_version' => 3,
    ]);

    $refreshed = app(EnsureFreshToken::class)->handle($connection);
    $raw = ProviderConnection::query()->whereKey($connection->id)->first()->getRawOriginal('access_token');

    expect($refreshed->access_token)->toStartWith('fake-access-refreshed')
        ->and($refreshed->token_version)->toBe(4)
        ->and($raw)->not->toStartWith('fake-access-refreshed')
        ->and(Crypt::decryptString($raw))->toStartWith('fake-access-refreshed');
});
