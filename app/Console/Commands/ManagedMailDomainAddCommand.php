<?php

namespace App\Console\Commands;

use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Mail\Services\ProvisionManagedDomain;
use App\Domain\Mail\Support\ManagedDomainNormalizer;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ManagedMailDomainAddCommand extends ManagedMailOperatorCommand
{
    protected $signature = 'managed-mail:domain-add
        {domain : Public domain to provision}
        {--workspace= : Required workspace ID}
        {--actor= : Optional verified owner user ID for audit attribution}
        {--instance= : Optional configured managed-mail instance key}
        {--managed-instance= : Alias for --instance}
        {--dry-run : Validate and print the normalised domain without provisioning}
        {--json : Emit machine-readable output}';

    protected $description = 'Provision one managed mail domain through the configured provider';

    public function handle(
        ManagedMailCapability $capability,
        ProvisionManagedDomain $provision,
    ): int {
        $context = $this->operatorContext($capability);
        if ($context === null) {
            return self::FAILURE;
        }

        $input = (string) $this->argument('domain');
        $normalized = ManagedDomainNormalizer::normalize($input);
        $organisational = $normalized === null ? null : ManagedDomainNormalizer::organisationalDomain($normalized);
        if ($normalized === null || $organisational === null) {
            $this->error('The requested value is not a valid public managed-mail domain.');

            return self::INVALID;
        }

        if ($this->option('dry-run')) {
            $this->emit([
                'workspace_id' => $context['workspace']->id,
                'domain' => $normalized,
                'organisational_domain' => $organisational,
                'dry_run' => true,
            ]);

            return self::SUCCESS;
        }

        $instance = $this->option('instance') ?: $this->option('managed-instance');
        $instance = is_string($instance) && trim($instance) !== '' ? trim($instance) : null;

        try {
            $domain = $provision->handle(
                $context['workspace'],
                $context['actor'],
                $normalized,
                $instance,
                ['correlation_id' => 'artisan-managed-mail-domain-add'],
            );

            $this->emit([
                'workspace_id' => $context['workspace']->id,
                'domain_id' => $domain->id,
                'domain' => $domain->domain,
                'status' => $this->enumValue($domain->status),
                'managed_instance_key' => $domain->managed_instance_key,
                'dry_run' => false,
            ]);

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error('Managed domain validation failed.');

            return self::INVALID;
        } catch (Throwable $exception) {
            $this->error($this->safeFailure($exception, 'Managed domain provisioning failed; inspect the local state before retrying.'));

            return self::FAILURE;
        }
    }

    private function enumValue(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (is_string($value) ? $value : null);
    }
}
