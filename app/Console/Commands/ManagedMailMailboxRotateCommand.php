<?php

namespace App\Console\Commands;

use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Mail\Services\RotateManagedMailboxCredential;
use Throwable;

final class ManagedMailMailboxRotateCommand extends ManagedMailOperatorCommand
{
    protected $signature = 'managed-mail:mailbox-rotate
        {account : Local managed mailbox account ID}
        {--workspace= : Required workspace ID}
        {--actor= : Optional verified owner user ID for audit attribution}
        {--dry-run : Validate the account without changing credentials}
        {--json : Emit machine-readable output}';

    protected $description = 'Rotate one managed mailbox credential through the configured provider';

    public function handle(
        ManagedMailCapability $capability,
        RotateManagedMailboxCredential $rotate,
    ): int {
        $context = $this->operatorContext($capability);
        if ($context === null) {
            return self::FAILURE;
        }

        $accountId = $this->positiveId($this->argument('account'));
        if ($accountId === null) {
            $this->error('The account argument must be a positive numeric ID.');

            return self::INVALID;
        }

        if ($this->option('dry-run')) {
            $this->emit([
                'workspace_id' => $context['workspace']->id,
                'account_id' => $accountId,
                'dry_run' => true,
            ]);

            return self::SUCCESS;
        }

        try {
            $account = $rotate->handle($context['workspace'], $context['actor'], $accountId);
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
        } catch (Throwable $exception) {
            $this->error($this->safeFailure($exception, 'Managed mailbox credential rotation failed; inspect the local state before retrying.'));

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
