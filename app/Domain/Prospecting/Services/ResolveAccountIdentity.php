<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\People\Support\DomainNormalizer;
use App\Models\Account;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class ResolveAccountIdentity
{
    /**
     * Resolve only deterministic account identities. A domain is trusted only
     * when it is present and not a consumer mailbox domain.
     *
     * @param  array<string,mixed>  $values
     */
    public function handle(Workspace $workspace, array $values, bool $confirmedDuplicate = false): ?Account
    {
        $domain = DomainNormalizer::normalize(
            isset($values['domain_normalized']) ? (string) $values['domain_normalized'] : (isset($values['domain']) ? (string) $values['domain'] : (isset($values['website']) ? (string) $values['website'] : null)),
        );
        if ($domain !== null && ! DomainNormalizer::isFreeMailDomain($domain)) {
            return Account::query()
                ->where('workspace_id', $workspace->id)
                ->where('domain_normalized', $domain)
                ->lockForUpdate()
                ->first();
        }

        $name = trim((string) ($values['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $conflicts = $this->nameQuery($workspace, $name)->get();
        if ($conflicts->isNotEmpty() && ! $confirmedDuplicate) {
            throw ValidationException::withMessages([
                'name' => 'Another account with this name exists in this workspace.',
                'confirmed_duplicate' => 'Create this account anyway to keep both records.',
            ]);
        }

        return null;
    }

    /** @param array<string,mixed> $values */
    public function resolve(Workspace $workspace, array $values, bool $confirmedDuplicate = false): ?Account
    {
        return $this->handle($workspace, $values, $confirmedDuplicate);
    }

    /** @return Builder<Account> */
    private function nameQuery(Workspace $workspace, string $name): Builder
    {
        return Account::query()
            ->where('workspace_id', $workspace->id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->orderBy('id');
    }
}
