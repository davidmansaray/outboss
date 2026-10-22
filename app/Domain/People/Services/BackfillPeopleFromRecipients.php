<?php

namespace App\Domain\People\Services;

use App\Models\CampaignRecipient;
use App\Models\Person;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class BackfillPeopleFromRecipients
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
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'email_address',
                'email_normalized',
                'full_name',
                'first_name',
                'account_id',
                'updated_at',
            ]);

        $existing = Person::query()
            ->where('workspace_id', $workspaceId)
            ->pluck('id', 'email_normalized');

        /** @var array<string, Collection<int, CampaignRecipient>> $groups */
        $groups = [];

        foreach ($recipients as $recipient) {
            $email = (string) $recipient->email_normalized;

            if ($email === '') {
                continue;
            }

            $groups[$email][] = $recipient;
        }

        foreach ($groups as $email => $members) {
            $members = Collection::make($members);
            $latest = $members->first();
            $personId = $existing->get($email);

            if ($personId === null) {
                $person = Person::query()->create([
                    'workspace_id' => $workspaceId,
                    'account_id' => $latest?->account_id,
                    'full_name' => $latest?->full_name,
                    'first_name' => $latest?->first_name,
                    'email_address' => (string) $latest?->email_address,
                    'email_normalized' => $email,
                    'job_title' => null,
                    'notes' => null,
                    'tags' => [],
                    'source' => Person::SOURCE_CAMPAIGN_BACKFILL,
                    'created_by_user_id' => null,
                ]);
                $personId = $person->id;
                $existing->put($email, $personId);
            }

            CampaignRecipient::query()
                ->whereIn('id', $members->pluck('id'))
                ->whereNull('person_id')
                ->update(['person_id' => $personId]);
        }
    }
}
