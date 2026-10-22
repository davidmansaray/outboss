<?php

use App\Models\AiProviderCredential;
use App\Models\AiProviderEvent;
use App\Models\AiUsageLedger;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the selected unified provider control plane with safe defaults', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('providers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('providers/Index')
            ->where('isOwner', true)
            ->where('initialTab', 'overview')
            ->where('initialActivityView', 'usage')
            ->missing('featureEnabled')
            ->has('providers', 2)
            ->where('providers.0.status', 'disconnected')
            ->has('tasks', 3)
            ->where('budget.currency', 'USD')
            ->where('budget.monthlyLimitMicros', 50_000_000)
            ->where('budget.tavilyCreditPriceMicros', 8_000),
        );
});

it('connects OpenRouter through fixed privacy controls and never persists or returns a key hint', function () {
    $user = User::factory()->create();
    $secret = 'sk-or-v1-private-test-secret';
    Http::fake([
        'https://openrouter.ai/api/v1/key' => Http::response(['data' => ['limit' => 10]], 200),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-123',
            'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 1, 'cost' => 0.001],
        ], 200, ['x-request-id' => 'req-123']),
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('providers.connect', 'openrouter'), ['secret' => $secret])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('providers.index'));

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/chat/completions')) {
            return true;
        }

        return $request['model'] === 'openai/gpt-5-mini'
            && $request['max_completion_tokens'] === 1
            && $request['response_format']['type'] === 'json_schema'
            && $request['response_format']['json_schema']['strict'] === true
            && $request['response_format']['json_schema']['schema']['additionalProperties'] === false
            && $request['provider']['allow_fallbacks'] === false
            && $request['provider']['require_parameters'] === true
            && $request['provider']['data_collection'] === 'deny'
            && $request['provider']['zdr'] === true;
    });
    Http::assertSentCount(2);

    $credential = AiProviderCredential::query()->sole();
    $rawSecret = DB::table('ai_provider_credential_versions')->value('encrypted_secret');
    expect($credential->status->value)->toBe('healthy')
        ->and($rawSecret)->not->toBe($secret)
        ->and($rawSecret)->not->toContain($secret)
        ->and(AiUsageLedger::query()->count())->toBe(1)
        ->and(AiProviderEvent::query()->where('event', 'added')->exists())->toBeTrue();

    $this->actingAs($user)
        ->get(route('providers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('providers.0.credentialDisplay', '••••••••••••')
            ->missing('providers.0.secret')
            ->where('providers.0.safeError', null),
        );
});

it('gives workspace members a read-only view and denies owner mutations', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $member = User::factory()->withoutWorkspace()->create();
    WorkspaceMembership::query()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $this->actingAs($member)
        ->get(route('providers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('isOwner', false)
            ->has('providers', 2)
            ->has('usage'),
        );

    $this->actingAs($member)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('providers.connect', 'tavily'), ['secret' => 'tvly-member-key'])
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('providers.audit'))
        ->assertForbidden();
});

it('requires recent password only when a budget change expands spending authority', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('providers.index'))->assertOk();

    $this->actingAs($user)
        ->put(route('providers.budget.update'), [
            'monthly_limit_usd' => '40.00',
            'tavily_credit_price_usd' => '0.009',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->actingAs($user)
        ->put(route('providers.budget.update'), [
            'monthly_limit_usd' => '60.00',
            'tavily_credit_price_usd' => '0.009',
        ])
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('providers.budget.update'), [
            'monthly_limit_usd' => '60.00',
            'tavily_credit_price_usd' => '0.007',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
});

it('keeps provider connections available without a global rollout flag', function () {
    $user = User::factory()->create();
    $secret = 'sk-or-no-rollout-flag-test';
    Http::fake([
        'https://openrouter.ai/api/v1/key' => Http::response(['data' => ['limit' => 10]], 200),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-no-rollout-flag',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'cost' => 0.0001],
        ], 200, ['x-request-id' => 'req-no-rollout-flag']),
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('providers.connect', 'openrouter'), ['secret' => $secret])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('providers.index'));

    expect(AiProviderCredential::query()->count())->toBe(1);
});

it('downgrades provider health with a safe error after a failed retest', function () {
    $user = User::factory()->create();
    Http::fake([
        'https://openrouter.ai/api/v1/key' => Http::sequence()
            ->push(['data' => []])
            ->push([], 401),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-health',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'cost' => 0.0001],
        ]),
    ]);
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('providers.connect', 'openrouter'), ['secret' => 'sk-or-health-test'])
        ->assertSessionHasNoErrors();
    $credential = AiProviderCredential::query()->sole();

    $this->actingAs($user)
        ->post(route('providers.test', $credential))
        ->assertSessionHasErrors('provider');

    expect($credential->fresh()->status->value)->toBe('misconfigured')
        ->and($credential->fresh()->last_error_code)->toBe('invalid_credential')
        ->and($credential->fresh()->last_error_message)->not->toContain('sk-or-health-test')
        ->and(AiProviderEvent::query()->where('event', 'health_changed')->exists())->toBeTrue();
});

it('filters the paginated metadata-only usage ledger', function () {
    $user = User::factory()->create();
    Http::fake([
        'https://openrouter.ai/api/v1/key' => Http::response(['data' => []]),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-usage',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'cost' => 0.0001],
        ]),
    ]);
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('providers.connect', 'openrouter'), ['secret' => 'sk-or-usage-test'])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->get(route('providers.usage', ['provider' => 'openrouter']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('initialTab', 'activity')
            ->where('initialActivityView', 'usage')
            ->where('usagePagination.total', 1)
            ->where('usageFilters.provider', 'openrouter')
            ->has('usage', 1)
            ->where('usage.0.status', 'succeeded')
            ->has('usage.0.correlationId')
            ->missing('usage.0.secret'));

    $this->actingAs($user)
        ->get(route('providers.usage', ['provider' => 'tavily']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('usagePagination.total', 0)
            ->has('usage', 0));
});
