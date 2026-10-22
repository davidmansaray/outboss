<?php

namespace App\Domain\People\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\People\Support\ProvenanceSummary;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Person;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class BuildPeopleAccountsRegistry
{
    /**
     * @return array{people: array<int, array<string, mixed>>, accounts: array<int, array<string, mixed>>, accountOptions: array<int, array{id: int, name: string}>}
     */
    public function for(Workspace $workspace): array
    {
        $suppressedEmails = $workspace->suppressions()
            ->pluck('email_normalized')
            ->filter()
            ->flip();

        $people = Person::query()
            ->where('workspace_id', $workspace->id)
            ->with([
                'account:id,name',
                'recipients' => fn ($query) => $query
                    ->where('workspace_id', $workspace->id)
                    ->with('campaign:id,name'),
            ])
            ->get()
            ->map(fn (Person $person): array => $this->personPayload($person, $suppressedEmails))
            ->sortByDesc('updated_at')
            ->values();

        $accounts = Account::query()
            ->where('workspace_id', $workspace->id)
            ->with([
                'people.recipients.campaign:id,name',
                'recipients.campaign:id,name',
            ])
            ->get()
            ->map(function (Account $account) use ($suppressedEmails): array {
                $members = $account->people
                    ->map(fn (Person $person): array => $this->personPayload($person, $suppressedEmails))
                    ->sortByDesc('updated_at')
                    ->values();
                $campaigns = $account->recipients
                    ->map(fn (CampaignRecipient $recipient): int => (int) $recipient->campaign_id)
                    ->unique()
                    ->values();
                $ready = $members->where('eligibility', 'Eligible')->count();
                $peopleCount = $members->count();

                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'domain' => $account->domain_normalized,
                    'website' => $account->website,
                    'notes' => $account->notes,
                    'tags' => $account->tags,
                    'source' => $account->source,
                    'provenance' => ProvenanceSummary::for($account),
                    'profile' => null,
                    'employee_count' => null,
                    'people_count' => $peopleCount,
                    'ready_count' => $ready,
                    'readiness' => $this->readiness($peopleCount, $ready),
                    'campaigns_count' => $campaigns->count(),
                    'people' => $members
                        ->map(fn (array $person): array => [
                            'id' => $person['id'],
                            'name' => $person['name'],
                            'email' => $person['email'],
                            'role' => $person['role'],
                            'reply' => $person['reply'],
                        ])
                        ->all(),
                    'updated_at' => $members->max('updated_at') ?? $account->updated_at?->toIso8601String(),
                ];
            })
            ->sortByDesc('updated_at')
            ->values();

        return [
            'people' => array_values($people->all()),
            'accounts' => array_values($accounts->all()),
            'accountOptions' => $accounts
                ->map(fn (array $account): array => [
                    'id' => $account['id'],
                    'name' => $account['name'],
                ])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<string, mixed>  $suppressedEmails
     * @return array<string, mixed>
     */
    private function personPayload(Person $person, Collection $suppressedEmails): array
    {
        $recipients = $person->recipients;
        $statusValues = collect($recipients
            ->map(fn (CampaignRecipient $recipient): string => $this->statusValue($recipient))
            ->all())
            ->unique()
            ->values();
        $campaigns = [];
        $seen = [];

        foreach ($recipients as $recipient) {
            if (isset($seen[$recipient->campaign_id])) {
                continue;
            }

            $seen[$recipient->campaign_id] = true;
            $campaign = $recipient->campaign;

            $campaigns[] = [
                'id' => $recipient->campaign_id,
                'name' => $campaign instanceof Campaign ? $campaign->name : '',
                'status' => $this->statusValue($recipient),
                'updated_at' => $recipient->updated_at?->toIso8601String(),
            ];
        }

        $eligibility = $suppressedEmails->has($person->email_normalized)
            || $statusValues->intersect([
                RecipientStatus::Unsubscribed->value,
                RecipientStatus::Bounced->value,
                RecipientStatus::Removed->value,
            ])->isNotEmpty()
                ? 'Suppressed'
                : 'Eligible';

        return [
            'id' => $person->id,
            'name' => $this->clean($person->full_name)
                ?? $this->clean($person->first_name)
                ?? $this->nameFromEmail($person->email_address),
            'email' => $person->email_address,
            'account_id' => $person->account_id !== null ? (int) $person->account_id : null,
            'account' => $person->account?->name,
            'role' => $person->job_title,
            'reply' => $recipients->isEmpty() ? 'No campaigns' : $this->replyLabel($statusValues),
            'eligibility' => $eligibility,
            'campaign_label' => count($campaigns) === 0
                ? '—'
                : (count($campaigns) === 1 ? $campaigns[0]['name'] : count($campaigns).' campaigns'),
            'campaigns' => $campaigns,
            'tags' => $person->tags ?? [],
            'notes' => $person->notes,
            'source' => $this->sourceLabel($person->source),
            'provenance' => ProvenanceSummary::for($person),
            'updated_at' => $person->updated_at?->toIso8601String(),
        ];
    }

    private function readiness(int $peopleCount, int $readyCount): string
    {
        if ($peopleCount === 0) {
            return 'No people';
        }

        if ($readyCount === $peopleCount) {
            return 'Ready';
        }

        return $readyCount === 0 ? 'Blocked' : 'Needs review';
    }

    private function statusValue(CampaignRecipient $recipient): string
    {
        $status = $recipient->getAttribute('status');

        return $status instanceof RecipientStatus ? $status->value : (string) $status;
    }

    /** @param Collection<int, string> $statuses */
    private function replyLabel(Collection $statuses): string
    {
        if ($statuses->contains(RecipientStatus::Interested->value)) {
            return 'Interested';
        }

        if ($statuses->contains(RecipientStatus::Replied->value)) {
            return 'Replied';
        }

        if ($statuses->contains(RecipientStatus::NotNow->value)) {
            return 'Not now';
        }

        return 'Awaiting reply';
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            Person::SOURCE_MANUAL => 'Added manually',
            Person::SOURCE_IMPORT => 'CSV import',
            Person::SOURCE_CAMPAIGN_BACKFILL => 'Campaign enrolment',
            default => $source,
        };
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nameFromEmail(string $email): string
    {
        $local = explode('@', $email, 2)[0];

        return str($local)->replace(['.', '_', '-'], ' ')->title()->toString();
    }
}
