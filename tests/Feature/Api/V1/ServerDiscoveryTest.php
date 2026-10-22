<?php

use App\Domain\Notifications\PushConfiguration;
use App\Models\User;

it('describes the server without authentication', function () {
    config(['outboss.version' => null]);

    $this->getJson('/api/v1/server')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'product' => 'outboss',
                'version' => 'dev',
                'api' => 'v1',
                'push' => false,
                'sign_in_url' => url('/mobile/authorize'),
            ],
        ]);
});

it('reports the configured version and an absolute sign-in URL', function () {
    config(['outboss.version' => ' 2026.10.1 ']);

    $response = $this->getJson('/api/v1/server')->assertOk();

    expect($response->json('data.version'))->toBe('2026.10.1')
        ->and($response->json('data.sign_in_url'))->toStartWith('http')
        ->and($response->json('data.sign_in_url'))->toEndWith('/mobile/authorize');
});

it('treats an empty version as dev', function () {
    config(['outboss.version' => '']);

    $this->getJson('/api/v1/server')->assertJsonPath('data.version', 'dev');
});

it('reports push readiness the same way as /me', function () {
    $this->mock(PushConfiguration::class, fn ($mock) => $mock->shouldReceive('available')->andReturn(true));
    $user = User::factory()->create();

    $this->getJson('/api/v1/server')->assertJsonPath('data.push', true);
    $this->withToken(apiToken($user, []))->getJson('/api/v1/me')->assertJsonPath('data.capabilities.push', true);
});

it('ignores bearer tokens, valid or not', function () {
    $this->withToken('not-a-token')->getJson('/api/v1/server')
        ->assertOk()
        ->assertJsonPath('data.product', 'outboss');
});

it('is rate limited per client address', function () {
    for ($i = 0; $i < 120; $i++) {
        $this->getJson('/api/v1/server')->assertOk();
    }

    $this->getJson('/api/v1/server')
        ->assertStatus(429)
        ->assertJsonPath('error.type', 'rate_limited');
});
