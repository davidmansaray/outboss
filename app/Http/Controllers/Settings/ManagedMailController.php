<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Domain\Mail\Exceptions\ManagedMailProvisioningException;
use App\Domain\Mail\Exceptions\StalwartManagementException;
use App\Domain\Mail\Services\DeleteManagedMailbox;
use App\Domain\Mail\Services\DeleteManagedMailDomain;
use App\Domain\Mail\Services\ProvisionManagedDomain;
use App\Domain\Mail\Services\ProvisionManagedMailbox;
use App\Domain\Mail\Services\RotateManagedMailboxCredential;
use App\Domain\Mail\Services\SuspendManagedMailbox;
use App\Domain\Mail\Services\VerifyManagedMailDomain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ConfirmManagedMailboxDeletionRequest;
use App\Http\Requests\Settings\ConfirmManagedMailDomainDeletionRequest;
use App\Http\Requests\Settings\StoreManagedMailboxRequest;
use App\Http\Requests\Settings\StoreManagedMailDomainRequest;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\User;
use App\Models\Workspace;
use BackedEnum;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Internal, owner-only managed-mail operations. */
final class ManagedMailController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();
        $domains = ManagedMailDomain::query()
            ->where('workspace_id', $workspace->id)
            ->with(['mailboxAccounts.mailbox.connection', 'createdBy'])
            ->orderBy('domain')
            ->get()
            ->map(fn (ManagedMailDomain $domain): array => $this->domain($domain))
            ->values()
            ->all();

        $instances = [];
        $configured = config('outboss.managed_mail.instances', []);
        if (is_array($configured)) {
            foreach ($configured as $key => $instance) {
                if (! is_array($instance)) {
                    continue;
                }

                // The management key is intentionally never sent to an
                // Inertia prop, even for this owner-only internal surface.
                $instances[(string) $key] = [
                    'base_url' => is_string($instance['base_url'] ?? null) ? $instance['base_url'] : null,
                    'verify_tls' => (bool) ($instance['verify_tls'] ?? true),
                    'timeout_seconds' => (int) ($instance['timeout_seconds'] ?? 15),
                ];
            }
        }

        return Inertia::render('managed-mail/Index', [
            'domains' => $domains,
            'defaultInstance' => (string) config('outboss.managed_mail.default_instance', 'internal'),
            'instances' => $instances,
            'canManage' => true,
        ]);
    }

    public function storeDomain(
        StoreManagedMailDomainRequest $request,
        ProvisionManagedDomain $provision,
    ): RedirectResponse {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();
        $data = $request->validated();

        try {
            $domain = $provision->handle(
                $workspace,
                $user,
                (string) $data['domain'],
                isset($data['managed_instance_key']) ? (string) $data['managed_instance_key'] : null,
                ['correlation_id' => $this->correlationId($request)],
            );

            return redirect()->route('managed-mail.index')
                ->with('success', $domain->domain.' is waiting for DNS verification.');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return redirect()->route('managed-mail.index')
                ->with('error', $this->safeMessage($exception, 'The managed domain could not be provisioned.'));
        }
    }

    public function verifyDomain(
        Request $request,
        int $domainId,
        VerifyManagedMailDomain $verify,
    ): RedirectResponse {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();
        $domain = $this->domainFor($workspace, $domainId);

        try {
            $verified = $verify->handle($workspace, $user, $domain);

            return redirect()->route('managed-mail.index')
                ->with('success', $verified->domain.' is verified and ready for managed mailboxes.');
        } catch (ValidationException $exception) {
            return redirect()->route('managed-mail.index')
                ->withErrors($exception->errors())
                ->with('error', 'DNS verification is not complete yet.');
        } catch (Throwable $exception) {
            return redirect()->route('managed-mail.index')
                ->with('error', $this->safeMessage($exception, 'The managed domain could not be verified.'));
        }
    }

    public function storeMailbox(
        StoreManagedMailboxRequest $request,
        int $domainId,
        ProvisionManagedMailbox $provision,
    ): RedirectResponse {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();
        $domain = $this->domainFor($workspace, $domainId);
        $data = $request->validated();

        try {
            $account = $provision->handle(
                $workspace,
                $user,
                $domain,
                (string) $data['local_part'],
                isset($data['display_name']) ? (string) $data['display_name'] : null,
                ['correlation_id' => $this->correlationId($request)],
            );

            return redirect()->route('managed-mail.index')
                ->with('success', $account->email_address.' is being prepared.');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return redirect()->route('managed-mail.index')
                ->with('error', $this->safeMessage($exception, 'The managed mailbox could not be provisioned.'));
        }
    }

    public function rotateCredential(
        Request $request,
        int $accountId,
        RotateManagedMailboxCredential $rotate,
    ): RedirectResponse {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();
        $account = $this->accountFor($workspace, $accountId);

        try {
            $rotated = $rotate->handle($workspace, $user, $account);

            return redirect()->route('managed-mail.index')
                ->with('success', $rotated->email_address.' credential rotated.');
        } catch (Throwable $exception) {
            return redirect()->route('managed-mail.index')
                ->with('error', $this->safeMessage($exception, 'The managed mailbox credential could not be rotated.'));
        }
    }

    public function suspendMailbox(
        Request $request,
        int $accountId,
        SuspendManagedMailbox $suspend,
    ): RedirectResponse {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();
        $account = $this->accountFor($workspace, $accountId);

        try {
            $suspended = $suspend->handle($workspace, $user, $account);

            return redirect()->route('managed-mail.index')
                ->with('success', $suspended->email_address.' suspended.');
        } catch (Throwable $exception) {
            return redirect()->route('managed-mail.index')
                ->with('error', $this->safeMessage($exception, 'The managed mailbox could not be suspended.'));
        }
    }

    public function destroyMailbox(
        ConfirmManagedMailboxDeletionRequest $request,
        int $accountId,
        DeleteManagedMailbox $delete,
    ): RedirectResponse {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();
        $account = $this->accountFor($workspace, $accountId);

        try {
            $deleted = $delete->handle($workspace, $user, $account);

            return redirect()->route('managed-mail.index')
                ->with('success', $deleted->email_address.' deleted.');
        } catch (Throwable $exception) {
            return redirect()->route('managed-mail.index')
                ->with('error', $this->safeMessage($exception, 'The managed mailbox could not be deleted.'));
        }
    }

    public function destroyDomain(
        ConfirmManagedMailDomainDeletionRequest $request,
        int $domainId,
        DeleteManagedMailDomain $delete,
    ): RedirectResponse {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();
        $domain = $this->domainFor($workspace, $domainId);

        try {
            $name = $domain->domain;
            $delete->handle($workspace, $user, $domain);

            return redirect()->route('managed-mail.index')
                ->with('success', $name.' retired. Its DNS records can now be removed.');
        } catch (Throwable $exception) {
            return redirect()->route('managed-mail.index')
                ->with('error', $this->safeMessage($exception, 'The managed domain could not be retired.'));
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function domainFor(Workspace $workspace, int $domainId): ManagedMailDomain
    {
        // Route parameters are scalar IDs on purpose. This query is the first
        // object lookup after managed-mail middleware, so cross-workspace
        // records resolve as 404 rather than leaking an ownership signal.
        return ManagedMailDomain::query()
            ->whereKey($domainId)
            ->where('workspace_id', $workspace->id)
            ->with('mailboxAccounts')
            ->firstOrFail();
    }

    private function accountFor(Workspace $workspace, int $accountId): ManagedMailboxAccount
    {
        return ManagedMailboxAccount::query()
            ->whereKey($accountId)
            ->whereHas('managedMailDomain', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->with(['managedMailDomain', 'mailbox.connection'])
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function domain(ManagedMailDomain $domain): array
    {
        $status = $this->enum($domain->status);
        $kind = $this->enum($domain->domain_kind);
        $provider = $this->enum($domain->provider);

        return [
            'id' => $domain->id,
            'domain' => $domain->domain,
            'organisational_domain' => $domain->organisational_domain,
            'domain_kind' => $kind,
            'provider' => $provider ?: MailProviderName::Stalwart->value,
            'managed_instance_key' => $domain->managed_instance_key,
            'remote_domain_id' => $domain->remote_domain_id,
            'status' => $status ?: ManagedMailDomainStatus::Draft->value,
            'dns_records' => $this->publicRecords(
                is_array($records = $domain->getAttribute('dns_records')) ? $records : [],
            ),
            'registered_at' => $this->date($domain->registered_at),
            'dns_activated_at' => $this->date($domain->dns_activated_at),
            'verified_at' => $this->date($domain->verified_at),
            'last_verified_at' => $this->date($domain->last_verified_at),
            'last_error_code' => $domain->last_error_code,
            'last_error_message' => $domain->last_error_message,
            'mailbox_accounts' => $domain->mailboxAccounts
                ->map(fn (ManagedMailboxAccount $account): array => $this->account($account))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function account(ManagedMailboxAccount $account): array
    {
        $mailbox = $account->mailbox;
        $connection = $mailbox?->connection;

        return [
            'id' => $account->id,
            'mailbox_id' => $account->mailbox_id,
            'email' => $account->email_address,
            'local_part' => $account->local_part,
            'display_name' => $account->display_name,
            'credential_version' => (int) $account->credential_version,
            'status' => $this->enum($account->status) ?: ManagedMailboxAccountStatus::Provisioning->value,
            'provisioned_at' => $this->date($account->provisioned_at),
            'last_verified_at' => $this->date($account->last_verified_at),
            'last_error_code' => $account->last_error_code,
            'last_error_message' => $account->last_error_message,
            'mailbox_status' => $this->enum($mailbox?->status) ?: null,
            'connection_status' => $this->enum($connection?->status) ?: null,
            'sending_paused_at' => $this->date($mailbox?->sending_paused_at),
            'sending_paused_reason' => $mailbox?->sending_paused_reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function publicRecords(array $values, int $depth = 0): array
    {
        if ($depth > 2) {
            return [];
        }

        $safe = [];
        foreach ($values as $key => $value) {
            $key = substr((string) $key, 0, 64);
            if ($key === '' || preg_match('/(?:pass(word)?|secret|token|credential|api[_-]?key|authorization|body|payload)/i', $key) === 1) {
                continue;
            }

            if (is_array($value)) {
                $nested = $this->publicRecords($value, $depth + 1);
                if ($nested !== []) {
                    $safe[$key] = $nested;
                }
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value)
                    ? ($key === 'zone_file'
                        ? substr(str_replace("\r\n", "\n", trim($value)), 0, 65535)
                        : substr(str_replace(["\r", "\n"], ' ', trim($value)), 0, 1000))
                    : $value;
            }
        }

        return $safe;
    }

    private function enum(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (is_string($value) ? $value : null);
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        return null;
    }

    private function correlationId(Request $request): string
    {
        $value = $request->header('X-Request-Id');

        return is_string($value) && preg_match('/\A[a-zA-Z0-9_.:-]{1,120}\z/', $value) === 1
            ? $value
            : (string) Str::uuid();
    }

    private function safeMessage(Throwable $exception, string $fallback): string
    {
        if (! $exception instanceof ManagedMailProvisioningException
            && ! $exception instanceof StalwartManagementException) {
            return $fallback;
        }

        $message = trim(str_replace(["\r", "\n"], ' ', $exception->getMessage()));

        return $message === '' ? $fallback : substr($message, 0, 500);
    }
}
