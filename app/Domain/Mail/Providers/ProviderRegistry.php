<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\Contracts\MailTransport;
use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\Contracts\OAuthMailboxConnector;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Exceptions\ProviderCapabilityUnavailable;
use InvalidArgumentException;

class ProviderRegistry
{
    /** @var array<string, ProviderDefinition> */
    private array $definitions = [];

    /**
     * Accept the old provider map as well as definitions so application and
     * package tests can migrate independently.
     *
     * @param  array<string, ProviderDefinition|MailTransport>  $providers
     */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $name => $provider) {
            $providerName = MailProviderName::from((string) $name);

            if ($provider instanceof ProviderDefinition) {
                $this->definitions[$providerName->value] = $provider;

                continue;
            }

            $this->definitions[$providerName->value] = new ProviderDefinition(
                name: $providerName,
                transport: $provider,
                oauth: $provider instanceof OAuthMailboxConnector ? $provider : null,
            );
        }
    }

    /** Legacy accessor; runtime callers should use transport() explicitly. */
    public function get(MailProviderName|string $provider): MailTransport
    {
        return $this->transport($provider);
    }

    public function definition(MailProviderName|string $provider): ProviderDefinition
    {
        $name = $this->name($provider);

        return $this->definitions[$name->value]
            ?? throw new InvalidArgumentException("Unknown mail provider [{$name->value}].");
    }

    public function transport(MailProviderName|string $provider): MailTransport
    {
        return $this->definition($provider)->transport;
    }

    public function oauth(MailProviderName|string $provider): OAuthMailboxConnector
    {
        $definition = $this->definition($provider);

        return $definition->oauth
            ?? throw new ProviderCapabilityUnavailable($definition->name->value, 'oauth');
    }

    public function provisioner(MailProviderName|string $provider): ManagedMailboxProvisioner
    {
        $definition = $this->definition($provider);

        return $definition->provisioner
            ?? throw new ProviderCapabilityUnavailable($definition->name->value, 'provisioning');
    }

    public function supportsOAuth(MailProviderName|string $provider): bool
    {
        return $this->definition($provider)->supportsOAuth();
    }

    public function supportsProvisioning(MailProviderName|string $provider): bool
    {
        return $this->definition($provider)->supportsProvisioning();
    }

    public function register(
        MailProviderName $name,
        MailTransport $transport,
        ?OAuthMailboxConnector $oauth = null,
        ?ManagedMailboxProvisioner $provisioner = null,
    ): void {
        // A legacy two-argument registration carries both capabilities when
        // the object implements the compatibility aggregate. Passing null
        // explicitly is how a transport-only provider remains OAuth-free.
        if (func_num_args() < 3 && $transport instanceof OAuthMailboxConnector) {
            $oauth = $transport;
        }

        $this->definitions[$name->value] = new ProviderDefinition(
            name: $name,
            transport: $transport,
            oauth: $oauth,
            provisioner: $provisioner,
        );
    }

    /** @return list<ProviderDefinition> */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }

    private function name(MailProviderName|string $provider): MailProviderName
    {
        return $provider instanceof MailProviderName ? $provider : MailProviderName::from($provider);
    }
}
