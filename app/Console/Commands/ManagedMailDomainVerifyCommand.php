<?php

namespace App\Console\Commands;

use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Mail\Services\VerifyManagedMailDomain;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ManagedMailDomainVerifyCommand extends ManagedMailOperatorCommand
{
    protected $signature = 'managed-mail:domain-verify
        {domain : Local managed domain ID}
        {--workspace= : Required workspace ID}
        {--actor= : Optional verified owner user ID for audit attribution}
        {--json : Emit machine-readable output}';

    protected $description = 'Verify one managed mail domain and refresh its DNS readiness evidence';

    public function handle(
        ManagedMailCapability $capability,
        VerifyManagedMailDomain $verify,
    ): int {
        $context = $this->operatorContext($capability);
        if ($context === null) {
            return self::FAILURE;
        }

        $domainId = $this->positiveId($this->argument('domain'));
        if ($domainId === null) {
            $this->error('The domain argument must be a positive numeric ID.');

            return self::INVALID;
        }

        try {
            $domain = $verify->handle($context['workspace'], $context['actor'], $domainId);
            $this->emit([
                'workspace_id' => $context['workspace']->id,
                'domain_id' => $domain->id,
                'domain' => $domain->domain,
                'status' => $this->enumValue($domain->status),
                'verified_at' => ($verifiedAt = $domain->getAttribute('verified_at')) instanceof \DateTimeInterface
                    ? $verifiedAt->format(DATE_ATOM)
                    : null,
            ]);

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error('Managed domain verification is not complete; inspect DNS evidence and retry.');

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($this->safeFailure($exception, 'Managed domain verification failed; inspect the local state before retrying.'));

            return self::FAILURE;
        }
    }

    private function positiveId(mixed $value): ?int
    {
        return is_scalar($value) && preg_match('/\A[1-9][0-9]*\z/', (string) $value) === 1
            ? (int) $value
            : null;
    }

    private function enumValue(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (is_string($value) ? $value : null);
    }
}
