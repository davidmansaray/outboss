<?php

namespace App\Console\Commands;

use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Mail\Services\ProvisionManagedMailbox;
use App\Domain\Mail\Services\RotateManagedMailboxCredential;
use App\Domain\Mail\Services\VerifyManagedMailDomain;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Throwable;

final class ManagedMailReconcileCommand extends ManagedMailOperatorCommand
{
    protected $signature = 'managed-mail:reconcile
        {--workspace= : Required workspace ID}
        {--actor= : Optional verified owner user ID for audit attribution}
        {--domain= : Optional domain ID (comma-separated IDs are accepted)}
        {--account= : Optional mailbox account ID (comma-separated IDs are accepted)}
        {--dry-run : Inspect recoverable local state without provider calls or writes}
        {--json : Emit machine-readable output}';

    protected $description = 'Reconcile managed domain and mailbox state through the existing application services';

    public function handle(
        ManagedMailCapability $capability,
        VerifyManagedMailDomain $verify,
        ProvisionManagedMailbox $provision,
        RotateManagedMailboxCredential $rotate,
    ): int {
        $context = $this->operatorContext($capability);
        if ($context === null) {
            return self::FAILURE;
        }

        $workspace = $context['workspace'];
        $domainIds = $this->ids($this->option('domain'));
        $accountIds = $this->ids($this->option('account'));
        $domains = $domainIds !== [] || $accountIds === []
            ? $this->domains($workspace->id, $domainIds)
            : new EloquentCollection;
        $accounts = $accountIds !== [] || $domainIds === []
            ? $this->accounts($workspace->id, $accountIds)
            : new EloquentCollection;

        if ($domainIds !== [] && $domains->count() !== count($domainIds)) {
            $this->error('One or more requested managed domains are not available in the workspace.');

            return self::FAILURE;
        }
        if ($accountIds !== [] && $accounts->count() !== count($accountIds)) {
            $this->error('One or more requested managed mailbox accounts are not available in the workspace.');

            return self::FAILURE;
        }

        if ($domainIds === [] && $accountIds === []) {
            $domains = $this->domains($workspace->id, [])
                ->filter(fn (ManagedMailDomain $domain): bool => in_array($this->enumValue($domain->status), [
                    ManagedMailDomainStatus::Provisioning->value,
                    ManagedMailDomainStatus::PendingDns->value,
                    ManagedMailDomainStatus::Verified->value,
                    ManagedMailDomainStatus::Active->value,
                    ManagedMailDomainStatus::Failed->value,
                ], true))
                ->values();
            $accounts = $this->accounts($workspace->id, [])
                ->filter(fn (ManagedMailboxAccount $account): bool => in_array($this->enumValue($account->status), [
                    ManagedMailboxAccountStatus::Provisioning->value,
                    ManagedMailboxAccountStatus::Failed->value,
                    ManagedMailboxAccountStatus::RotationRequired->value,
                ], true))
                ->values();
        }

        if ($this->option('dry-run')) {
            $this->emit([
                'workspace_id' => $workspace->id,
                'domain_ids' => $domains->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
                'account_ids' => $accounts->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
                'dry_run' => true,
            ]);

            return self::SUCCESS;
        }

        $verified = 0;
        $repaired = 0;
        $failed = 0;

        foreach ($domains as $domain) {
            try {
                $verify->handle($workspace, $context['actor'], $domain);
                $verified++;
            } catch (Throwable $exception) {
                $failed++;
            }
        }

        foreach ($accounts as $account) {
            try {
                $account->loadMissing('managedMailDomain');
                $domain = $account->managedMailDomain;
                if ($domain === null) {
                    $failed++;

                    continue;
                }

                $status = $this->accountStatus($account);
                $reconciled = $status === ManagedMailboxAccountStatus::RotationRequired
                    ? $rotate->handle($workspace, $context['actor'], $account)
                    : $provision->activate(
                        $workspace,
                        $context['actor'],
                        $provision->handle(
                            $workspace,
                            $context['actor'],
                            $domain,
                            (string) $account->local_part,
                            is_string($account->display_name) ? $account->display_name : null,
                            ['correlation_id' => 'artisan-managed-mail-reconcile'],
                        ),
                    );

                if (! $this->isHealthy($reconciled)) {
                    $failed++;

                    continue;
                }

                $repaired++;
            } catch (Throwable $exception) {
                $failed++;
            }
        }

        $this->emit([
            'workspace_id' => $workspace->id,
            'domains_verified' => $verified,
            'accounts_repaired' => $repaired,
            'failures' => $failed,
            'dry_run' => false,
        ]);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<int> */
    private function ids(mixed $value): array
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', (string) $value) as $part) {
            $part = trim($part);
            if (preg_match('/\A[1-9][0-9]*\z/', $part) !== 1) {
                return [-1];
            }
            $ids[] = (int) $part;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $ids
     * @return EloquentCollection<int, ManagedMailDomain>
     */
    private function domains(int $workspaceId, array $ids): EloquentCollection
    {
        $query = ManagedMailDomain::query()->where('workspace_id', $workspaceId)->orderBy('id');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    /**
     * @param  list<int>  $ids
     * @return EloquentCollection<int, ManagedMailboxAccount>
     */
    private function accounts(int $workspaceId, array $ids): EloquentCollection
    {
        $query = ManagedMailboxAccount::query()
            ->whereHas('managedMailDomain', fn ($domain) => $domain->where('workspace_id', $workspaceId))
            ->with('managedMailDomain')
            ->orderBy('id');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    private function enumValue(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (is_string($value) ? $value : null);
    }

    private function accountStatus(ManagedMailboxAccount $account): ManagedMailboxAccountStatus
    {
        $status = $account->getAttribute('status');

        return $status instanceof ManagedMailboxAccountStatus
            ? $status
            : ManagedMailboxAccountStatus::from((string) $status);
    }

    private function isHealthy(ManagedMailboxAccount $account): bool
    {
        $account = $account->fresh(['managedMailDomain', 'mailbox.connection']);
        $mailbox = $account?->mailbox;
        $connection = $mailbox?->connection;

        return $account !== null
            && $this->accountStatus($account) === ManagedMailboxAccountStatus::Active
            && $account->managedMailDomain?->isActive() === true
            && $account->last_verified_at !== null
            && $mailbox !== null
            && $this->enumValue($mailbox->getAttribute('status')) === ConnectionStatus::Connected->value
            && $connection !== null
            && $this->enumValue($connection->getAttribute('status')) === ConnectionStatus::Connected->value
            && $connection->hasUsableCredentials();
    }
}
