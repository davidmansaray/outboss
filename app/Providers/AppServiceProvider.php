<?php

namespace App\Providers;

use App\Domain\Ai\Providers\AiProviderRegistry;
use App\Domain\Ai\Providers\DeterministicOpenRouterAdapter;
use App\Domain\Ai\Providers\DeterministicTavilyAdapter;
use App\Domain\Ai\Providers\OpenRouterAdapter;
use App\Domain\Ai\Providers\TavilyAdapter;
use App\Domain\Integrations\Connections\ConnectionManager;
use App\Domain\Integrations\Connections\ConnectionTester;
use App\Domain\Integrations\Connections\RegistrationManager;
use App\Domain\Integrations\Contracts\ExternalBudgetGateway;
use App\Domain\Integrations\Operations\ExecuteExternalOperation;
use App\Domain\Integrations\Operations\IntegrationBudgetGateway;
use App\Domain\Integrations\Operations\IntegrationCostEstimator;
use App\Domain\Integrations\Providers\AiCapabilityBridge;
use App\Domain\Integrations\Providers\FakeCapabilityProvider;
use App\Domain\Integrations\Providers\ProviderRegistry as IntegrationProviderRegistry;
use App\Domain\Integrations\Support\CapabilityCatalogue;
use App\Domain\Integrations\Support\CapabilitySchemaValidator;
use App\Domain\Integrations\Support\ExternalErrorRedactor;
use App\Domain\Intelligence\Contracts\IntelligenceProviderBridge;
use App\Domain\Intelligence\Services\Af01IntelligenceProviderBridge;
use App\Domain\Mail\Contracts\DnsRecordResolver;
use App\Domain\Mail\Contracts\DnsResolver;
use App\Domain\Mail\Contracts\DomainAuthChecker;
use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\Contracts\ManagedMailEndpointChecker;
use App\Domain\Mail\Contracts\OAuthMailboxConnector;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Providers\FakeManagedMailboxProvisioner;
use App\Domain\Mail\Providers\GmailProvider;
use App\Domain\Mail\Providers\MicrosoftProvider;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Domain\Mail\Providers\StalwartManagedMailboxProvisioner;
use App\Domain\Mail\Providers\StalwartProvider;
use App\Domain\Mail\Providers\ZohoProvider;
use App\Domain\Mail\Services\DnsDomainAuthChecker;
use App\Domain\Mail\Services\DnsManagedMailEndpointChecker;
use App\Domain\Mail\Services\DnsMxResolver;
use App\Domain\Mail\Services\DohDnsRecordResolver;
use App\Domain\Mail\Services\FakeDnsResolver;
use App\Domain\Mail\Services\FakeDomainAuthChecker;
use App\Domain\Mail\Services\FakeManagedMailEndpointChecker;
use App\Domain\Notifications\ApnsPushGateway;
use App\Domain\Notifications\NullPushGateway;
use App\Domain\Notifications\PushConfiguration;
use App\Domain\Notifications\PushGateway;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Push is on exactly when the APNs settings validate; otherwise alerts
        // are dropped and /api/v1/me reports push as unavailable.
        $this->app->bind(PushGateway::class, function ($app): PushGateway {
            $credentials = $app->make(PushConfiguration::class)->apns();

            return $credentials === null ? new NullPushGateway : new ApnsPushGateway($credentials);
        });
        $this->app->singleton(CapabilityCatalogue::class);
        $this->app->singleton(CapabilitySchemaValidator::class);
        $this->app->singleton(IntegrationProviderRegistry::class, function ($app): IntegrationProviderRegistry {
            $registry = new IntegrationProviderRegistry;
            $registry->register($app->make(FakeCapabilityProvider::class));
            $registry->register($app->make(AiCapabilityBridge::class));

            return $registry;
        });
        $this->app->singleton(IntegrationBudgetGateway::class);
        $this->app->alias(IntegrationBudgetGateway::class, ExternalBudgetGateway::class);
        $this->app->singleton(IntegrationCostEstimator::class);
        $this->app->singleton(ExternalErrorRedactor::class);
        $this->app->singleton(ConnectionManager::class);
        $this->app->singleton(ConnectionTester::class);
        $this->app->singleton(RegistrationManager::class);
        $this->app->singleton(ExecuteExternalOperation::class);

        $this->app->singleton(AiProviderRegistry::class, function ($app): AiProviderRegistry {
            $browserFake = $app->environment('testing')
                && (bool) config('intelligence.testing.fake_providers', false);

            return new AiProviderRegistry([
                'openrouter' => $browserFake
                    ? $app->make(DeterministicOpenRouterAdapter::class)
                    : $app->make(OpenRouterAdapter::class),
                'tavily' => $browserFake
                    ? $app->make(DeterministicTavilyAdapter::class)
                    : $app->make(TavilyAdapter::class),
            ]);
        });
        $this->app->bind(IntelligenceProviderBridge::class, Af01IntelligenceProviderBridge::class);

        $this->app->singleton(ProviderRegistry::class, function () {
            $registry = new ProviderRegistry;

            foreach (MailProviderName::cases() as $provider) {
                $implementation = app()->environment('testing')
                    ? new FakeMailProvider($provider)
                    : match ($provider) {
                        MailProviderName::Gmail => new GmailProvider,
                        MailProviderName::Microsoft => new MicrosoftProvider,
                        MailProviderName::Zoho => new ZohoProvider,
                        MailProviderName::Stalwart => new StalwartProvider,
                    };
                $oauth = $implementation instanceof OAuthMailboxConnector ? $implementation : null;
                $provisioner = $provider === MailProviderName::Stalwart
                    ? (app()->environment('testing')
                        ? new FakeManagedMailboxProvisioner
                        : new StalwartManagedMailboxProvisioner)
                    : null;

                // Stalwart is a transport-only provider. Passing null
                // explicitly keeps the testing fake from advertising the
                // compatibility OAuth capability for managed connections.
                $registry->register(
                    $provider,
                    $implementation,
                    $provider === MailProviderName::Stalwart ? null : $oauth,
                    $provisioner,
                );
            }

            return $registry;
        });

        $this->app->bind(
            ManagedMailboxProvisioner::class,
            fn ($app): ManagedMailboxProvisioner => $app->make(ProviderRegistry::class)
                ->provisioner(MailProviderName::Stalwart),
        );

        $this->app->singleton(DnsRecordResolver::class, fn (): DnsRecordResolver => new DohDnsRecordResolver);

        $this->app->bind(DomainAuthChecker::class, function () {
            return app()->environment('testing')
                ? new FakeDomainAuthChecker
                : new DnsDomainAuthChecker(app(DnsRecordResolver::class));
        });

        $this->app->bind(ManagedMailEndpointChecker::class, function () {
            return app()->environment('testing')
                ? new FakeManagedMailEndpointChecker
                : new DnsManagedMailEndpointChecker(app(DnsRecordResolver::class));
        });

        $this->app->bind(DnsResolver::class, function () {
            return app()->environment('testing')
                ? new FakeDnsResolver
                : new DnsMxResolver(app(DnsRecordResolver::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('ingress', fn (Request $request) => Limit::perMinute(300)
            ->by('ip:'.$request->ip()));

        RateLimiter::for('auth-token', fn (Request $request) => Limit::perMinute(20)
            ->by('auth-token:'.$request->ip()));

        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();
            $token = $user?->currentAccessToken();
            $key = $token instanceof PersonalAccessToken
                ? 'token:'.$token->id
                : ($user !== null ? 'user:'.$user->id : 'ip:'.$request->ip());

            return Limit::perMinute(120)->by($key);
        });

        RateLimiter::for('mcp', function (Request $request) {
            $user = $request->user();
            $token = $user?->currentAccessToken();
            $key = $token instanceof PersonalAccessToken
                ? 'token:'.$token->id
                : ($user !== null ? 'user:'.$user->id : 'ip:'.$request->ip());

            return Limit::perMinute(60)->by($key);
        });

        RateLimiter::for('gtm-autosave', fn (Request $request): array => $this->workspaceRateLimits(
            $request,
            'gtm-autosave',
            (int) config('gtm.throttles.autosave_per_minute_per_user', 120),
            (int) config('gtm.throttles.autosave_per_minute_per_workspace', 300),
        ));

        RateLimiter::for('gtm-mutations', fn (Request $request): array => $this->workspaceRateLimits(
            $request,
            'gtm-mutations',
            (int) config('gtm.throttles.mutations_per_minute_per_user', 60),
            (int) config('gtm.throttles.mutations_per_minute_per_workspace', 180),
        ));

        RateLimiter::for('gtm-research', fn (Request $request): array => $this->workspaceRateLimits(
            $request,
            'gtm-research',
            (int) config('gtm.throttles.research_per_minute_per_user', 6),
            (int) config('gtm.throttles.research_per_minute_per_workspace', 12),
        ));

        RateLimiter::for('gtm-export', fn (Request $request): array => $this->workspaceRateLimits(
            $request,
            'gtm-export',
            (int) config('gtm.throttles.export_per_minute_per_user', 2),
            (int) config('gtm.throttles.export_per_minute_per_workspace', 5),
        ));
    }

    /**
     * Apply independent actor and workspace ceilings. Autosave receives a
     * deliberately high allowance while still protecting a workspace from a
     * runaway tab or coordinated mutation burst.
     *
     * @return list<Limit>
     */
    private function workspaceRateLimits(
        Request $request,
        string $scope,
        int $perUser,
        int $perWorkspace,
    ): array {
        $user = $request->user();
        $userKey = $user instanceof User
            ? 'user:'.$user->id
            : 'ip:'.$request->ip();
        $workspaceKey = 'unresolved:'.$userKey;

        if ($user instanceof User) {
            try {
                $workspaceKey = 'workspace:'.$user->currentWorkspace()->id;
            } catch (\Throwable) {
                // Authentication can exist briefly without a valid active
                // membership. Keep the limiter fail-safe and let the route's
                // normal workspace resolution return the authoritative error.
            }
        }

        return [
            Limit::perMinute(max(1, $perUser))->by($scope.':'.$userKey),
            Limit::perMinute(max(1, $perWorkspace))->by($scope.':'.$workspaceKey),
        ];
    }
}
