<?php

namespace App\Domain\People\Services;

use App\Domain\People\Support\DomainNormalizer;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Models\Account;
use App\Models\ProspectSource;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AccountEditor
{
    public function __construct(private readonly ProspectIngestionService $ingestion) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        Workspace $workspace,
        User $actor,
        array $data,
        ?string $idempotencyKey = null,
        ?string $sourceKey = null,
    ): Account {
        $sourceKey ??= 'manual';
        $account = new Account([
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $actor->id,
            'source' => Account::SOURCE_MANUAL,
        ]);

        $isReplay = $idempotencyKey !== null && $this->hasIngestionRun($workspace, $idempotencyKey, $sourceKey);
        if (! $isReplay) {
            $this->fill($account, $data, requireDuplicateConfirmation: ! ($data['confirmed_duplicate'] ?? false));
        }

        if ($isReplay) {
            $website = $this->nullableString($data['website'] ?? null);
            $accountData = [
                'name' => trim((string) $data['name']),
                'website' => $website,
                // Keep the replay fingerprint identical to the first
                // ingestion call. The first call passes the editor's derived
                // domain, so a retry must bind the same canonical payload
                // before the ingestion service returns the stored result.
                'domain_normalized' => DomainNormalizer::normalize($website),
                'notes' => $this->nullableString($data['notes'] ?? null),
                'tags' => $this->normalisedTags(is_array($data['tags'] ?? null) ? $data['tags'] : null),
                'confirmed_duplicate' => (bool) ($data['confirmed_duplicate'] ?? false),
            ];

            return $this->ingestion->createManualAccount(
                workspace: $workspace,
                actor: $actor,
                record: $accountData,
                idempotencyKey: $idempotencyKey,
                sourceKey: $sourceKey,
            );
        }

        return $this->ingestion->createManualAccount(
            workspace: $workspace,
            actor: $actor,
            record: [
                'name' => $account->name,
                'website' => $account->website,
                'domain_normalized' => $account->domain_normalized,
                'notes' => $account->notes,
                'tags' => $account->tags ?? [],
                'confirmed_duplicate' => (bool) ($data['confirmed_duplicate'] ?? false),
            ],
            idempotencyKey: $idempotencyKey,
            sourceKey: $sourceKey,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Account $account, array $data): Account
    {
        $this->fill($account, $data, requireDuplicateConfirmation: ! ($data['confirmed_duplicate'] ?? false));
        $this->save($account);

        return $account->fresh();
    }

    public function delete(Account $account, User $actor): int
    {
        return app(DeleteAccount::class)->handle($account, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(Account $account, array $data, bool $requireDuplicateConfirmation): void
    {
        $name = trim((string) $data['name']);
        $website = $this->nullableString($data['website'] ?? null);
        $domain = DomainNormalizer::normalize($website);
        $workspaceId = (int) $account->workspace_id;

        if ($domain !== null) {
            $conflict = $this->accountUsingDomain($workspaceId, $domain, $account->id);

            if ($conflict !== null) {
                throw ValidationException::withMessages([
                    'website' => $this->domainConflictMessage($conflict, $domain),
                ]);
            }
        } elseif ($requireDuplicateConfirmation) {
            $conflicts = $this->nameConflicts($workspaceId, $name, $account->id);

            if ($conflicts->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'name' => $this->nameConflictMessage($conflicts),
                    'confirmed_duplicate' => 'Create this account anyway to keep both records.',
                ]);
            }
        }

        $tags = $data['tags'] ?? null;

        $account->fill([
            'name' => $name,
            'website' => $website,
            'domain_normalized' => $domain,
            'notes' => $this->nullableString($data['notes'] ?? null),
            'tags' => $this->normalisedTags(is_array($tags) ? $tags : null),
        ]);
    }

    private function save(Account $account): void
    {
        try {
            $account->save();
        } catch (UniqueConstraintViolationException) {
            $domain = (string) $account->domain_normalized;
            $conflict = $this->accountUsingDomain((int) $account->workspace_id, $domain, $account->id);

            throw ValidationException::withMessages([
                'website' => $conflict instanceof Account
                    ? $this->domainConflictMessage($conflict, $domain)
                    : "Another account already uses {$domain}.",
            ]);
        }
    }

    private function accountUsingDomain(int $workspaceId, string $domain, ?int $exceptId): ?Account
    {
        return Account::query()
            ->where('workspace_id', $workspaceId)
            ->where('domain_normalized', $domain)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->first();
    }

    private function hasIngestionRun(Workspace $workspace, string $idempotencyKey, string $sourceKey): bool
    {
        $sourceId = ProspectSource::query()
            ->where('workspace_id', $workspace->id)
            ->where('system_key', $sourceKey)
            ->value('id');

        return $sourceId !== null && SourceRun::query()
            ->where('workspace_id', $workspace->id)
            ->where('prospect_source_id', $sourceId)
            ->where('idempotency_key', $idempotencyKey)
            ->exists();
    }

    /**
     * @return Collection<int, Account>
     */
    private function nameConflicts(int $workspaceId, string $name, ?int $exceptId): Collection
    {
        return Account::query()
            ->where('workspace_id', $workspaceId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->orderBy('id')
            ->get(['id', 'name']);
    }

    private function domainConflictMessage(Account $account, string $domain): string
    {
        return "Another account, ‘{$account->name}’, already uses {$domain}.";
    }

    /**
     * @param  Collection<int, Account>  $conflicts
     */
    private function nameConflictMessage(Collection $conflicts): string
    {
        $names = $conflicts->pluck('name')->unique()->values();

        if ($names->count() === 1) {
            return "Another account named ‘{$names->first()}’ already exists in this workspace.";
        }

        return 'Other accounts with this name already exist in this workspace.';
    }

    /**
     * @param  array<int, mixed>|null  $tags
     * @return array<int, string>
     */
    private function normalisedTags(?array $tags): array
    {
        $normalised = [];

        foreach ($tags ?? [] as $tag) {
            $value = trim((string) $tag);

            if ($value === '' || in_array($value, $normalised, true)) {
                continue;
            }

            $normalised[] = $value;

            if (count($normalised) === 20) {
                break;
            }
        }

        return $normalised;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
