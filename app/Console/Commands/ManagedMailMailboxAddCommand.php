<?php

namespace App\Console\Commands;

use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Mail\Services\ProvisionManagedMailbox;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ManagedMailMailboxAddCommand extends ManagedMailOperatorCommand
{
    protected $signature = 'managed-mail:mailbox-add
        {domain : Local managed domain ID}
        {local_part : Mailbox local part}
        {--workspace= : Required workspace ID}
        {--actor= : Optional verified owner user ID for audit attribution}
        {--display-name= : Optional sender display name}
        {--dry-run : Validate the local part without provisioning}
        {--json : Emit machine-readable output}';

    protected $description = 'Provision one managed Stalwart mailbox through the configured provider';

    public function handle(
        ManagedMailCapability $capability,
        ProvisionManagedMailbox $provision,
    ): int {
        $context = $this->operatorContext($capability);
        if ($context === null) {
            return self::FAILURE;
        }

        $domainId = $this->positiveId($this->argument('domain'));
        $localPart = trim((string) $this->argument('local_part'));
        if ($domainId === null || $localPart === '') {
            $this->error('The domain argument must be a positive numeric ID and local_part must not be empty.');

            return self::INVALID;
        }

        if ($this->option('dry-run')) {
            $this->emit([
                'workspace_id' => $context['workspace']->id,
                'domain_id' => $domainId,
                'local_part' => $localPart,
                'dry_run' => true,
            ]);

            return self::SUCCESS;
        }

        $displayName = $this->option('display-name');
        $displayName = is_string($displayName) && trim($displayName) !== '' ? trim($displayName) : null;

        try {
            $account = $provision->handle(
                $context['workspace'],
                $context['actor'],
                $domainId,
                $localPart,
                $displayName,
                ['correlation_id' => 'artisan-managed-mail-mailbox-add'],
            );

            $this->emit([
                'workspace_id' => $context['workspace']->id,
                'account_id' => $account->id,
                'mailbox_id' => $account->mailbox_id,
                'email' => $account->email_address,
                'status' => $this->enumValue($account->status),
                'credential_version' => (int) $account->credential_version,
                'dry_run' => false,
            ]);

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error('Managed mailbox validation failed.');

            return self::INVALID;
        } catch (Throwable $exception) {
            $this->error($this->safeFailure($exception, 'Managed mailbox provisioning failed; inspect the local state before retrying.'));

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
