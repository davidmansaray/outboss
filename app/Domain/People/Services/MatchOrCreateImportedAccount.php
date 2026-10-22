<?php

namespace App\Domain\People\Services;

use App\Domain\People\Support\DomainNormalizer;
use App\Domain\Prospecting\DTOs\IngestionBatch;
use App\Domain\Prospecting\DTOs\IngestionObservation;
use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Models\Account;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class MatchOrCreateImportedAccount
{
    /**
     * @var Collection<string, Account>
     */
    private Collection $byDomain;

    /**
     * @var Collection<string, Account>
     */
    private Collection $byName;

    public function __construct(
        private Workspace $workspace,
        private ?ProspectIngestionService $ingestion = null,
        private ?CreateProspectSource $sources = null,
    ) {
        $accounts = Account::query()
            ->where('workspace_id', $workspace->id)
            ->get();

        $this->byDomain = $accounts
            ->filter(fn (Account $account): bool => filled($account->domain_normalized))
            ->keyBy('domain_normalized');

        $this->byName = Collection::make();

        foreach ($accounts as $account) {
            $this->byName->put(mb_strtolower($account->name), $account);
        }
    }

    public function handle(?string $email, ?string $company): ?Account
    {
        $company = trim((string) $company);
        $domain = DomainNormalizer::fromEmail($email);

        if ($domain !== null && ! DomainNormalizer::isFreeMailDomain($domain) && $this->byDomain->has($domain)) {
            return $this->byDomain->get($domain);
        }

        if ($company !== '' && $this->byName->has(mb_strtolower($company))) {
            return $this->byName->get(mb_strtolower($company));
        }

        if ($company === '') {
            return null;
        }

        $accountDomain = $domain !== null && ! DomainNormalizer::isFreeMailDomain($domain) && ! $this->byDomain->has($domain)
            ? $domain
            : null;

        $this->ingestion ??= app(ProspectIngestionService::class);
        $this->sources ??= app(CreateProspectSource::class);
        $source = $this->sources->ensureSystemSource(
            workspace: $this->workspace,
            systemKey: 'legacy_import',
            kind: ProspectSourceKind::CsvImport,
            displayName: 'Legacy import',
            configuration: [
                'mapping' => ['email' => 'email'],
                'import_motion' => 'people',
            ],
        );
        $result = $this->ingestion->handle(new IngestionBatch(
            workspace: $this->workspace,
            source: $source,
            sourceVersion: $source->currentVersion()->first(),
            idempotencyKey: 'import-account:'.hash('sha256', $company.'|'.($accountDomain ?? '')),
            observations: [new IngestionObservation(
                entityType: 'account',
                observedValues: [
                    'name' => $company,
                    'domain_normalized' => $accountDomain,
                ],
            )],
            inputReference: ['adapter' => 'people_import'],
            initiatorType: 'system',
        ));
        $account = $result->firstCanonical();
        if (! $account instanceof Account) {
            return null;
        }

        $this->remember($account);

        return $account;
    }

    private function remember(Account $account): void
    {
        if ($account->domain_normalized !== null) {
            $this->byDomain->put($account->domain_normalized, $account);
        }

        $this->byName->put(mb_strtolower($account->name), $account);
    }
}
