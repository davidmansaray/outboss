<?php

namespace App\Domain\People\Services;

use App\Domain\People\Support\DomainNormalizer;
use App\Models\Account;
use App\Models\CampaignRecipient;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class BackfillAccountsFromRecipients
{
    public function handle(): void
    {
        Workspace::query()
            ->orderBy('id')
            ->each(fn (Workspace $workspace) => $this->backfillWorkspace((int) $workspace->id));
    }

    public function backfillWorkspace(int $workspaceId): void
    {
        $recipients = CampaignRecipient::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('account_id')
            ->get(['id', 'company', 'email_address']);

        /** @var array<string, Collection<int, CampaignRecipient>> $groups */
        $groups = [];

        foreach ($recipients as $recipient) {
            $company = trim((string) $recipient->company);

            if ($company === '') {
                continue;
            }

            $groups[mb_strtolower($company)][] = $recipient;
        }

        $existingByDomain = Account::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('domain_normalized')
            ->get()
            ->keyBy('domain_normalized');

        foreach ($groups as $members) {
            $members = Collection::make($members);
            $domain = $this->sharedCorporateDomain($members);
            $account = $domain !== null ? $existingByDomain->get($domain) : null;

            if ($account === null) {
                $account = Account::query()->create([
                    'workspace_id' => $workspaceId,
                    'name' => $this->mostCommonName($members),
                    'website' => null,
                    'domain_normalized' => $domain,
                    'notes' => null,
                    'tags' => [],
                    'source' => Account::SOURCE_CAMPAIGN_BACKFILL,
                    'created_by_user_id' => null,
                ]);

                if ($domain !== null) {
                    $existingByDomain->put($domain, $account);
                }
            }

            CampaignRecipient::query()
                ->whereIn('id', $members->pluck('id'))
                ->update(['account_id' => $account->id]);
        }
    }

    /**
     * @param  Collection<int, CampaignRecipient>  $members
     */
    private function sharedCorporateDomain(Collection $members): ?string
    {
        $domains = $members
            ->map(fn (CampaignRecipient $recipient): ?string => DomainNormalizer::fromEmail($recipient->email_address))
            ->unique()
            ->values();

        if ($domains->count() !== 1) {
            return null;
        }

        $domain = $domains->first();

        if (! is_string($domain) || DomainNormalizer::isFreeMailDomain($domain)) {
            return null;
        }

        return $domain;
    }

    /**
     * @param  Collection<int, CampaignRecipient>  $members
     */
    private function mostCommonName(Collection $members): string
    {
        $name = $members
            ->map(fn (CampaignRecipient $recipient): string => trim((string) $recipient->company))
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        return is_string($name) ? $name : 'Account';
    }
}
