<?php

namespace Database\Seeders;

use App\Domain\Campaigns\DTOs\EnrollmentCandidate;
use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Campaigns\Services\EnrollPeopleInCampaign;
use App\Domain\Campaigns\Services\SequenceStepEditor;
use App\Domain\Mail\Enums\SuppressionReason;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\People\Support\DomainNormalizer;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\Mailbox;
use App\Models\Person;
use App\Models\SequenceStep;
use App\Models\SuppressionEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WorkflowDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public const CAMPAIGN_NAME = 'Workflow verification';

    public function run(): void
    {
        $user = User::query()->where('email', UniboxDemoSeeder::USER_EMAIL)->first();

        if (! $user instanceof User) {
            $this->call(UniboxDemoSeeder::class);
            $user = User::query()->where('email', UniboxDemoSeeder::USER_EMAIL)->firstOrFail();
        }

        $workspace = $user->currentWorkspace();
        $accounts = $this->accounts($workspace, $user);
        $people = $this->people($workspace, $user, $accounts);
        $this->suppressions($workspace, $user);
        $campaign = $this->campaign($user, $workspace);
        $this->sequence($campaign);
        $this->enroll($campaign, $people);
    }

    /**
     * @return array<string, Account>
     */
    private function accounts(Workspace $workspace, User $user): array
    {
        $accounts = [];

        foreach ($this->accountFixtures() as $fixture) {
            $domain = DomainNormalizer::normalize($fixture['domain']);

            if ($domain === null) {
                throw new RuntimeException("Invalid workflow demo domain: {$fixture['domain']}");
            }

            $accounts[$fixture['domain']] = Account::query()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'domain_normalized' => $domain,
                ],
                [
                    'name' => $fixture['name'],
                    'website' => 'https://'.$fixture['domain'],
                    'notes' => $fixture['notes'],
                    'tags' => $fixture['tags'],
                    'source' => $fixture['source'],
                    'created_by_user_id' => $user->id,
                ],
            );
        }

        return $accounts;
    }

    /**
     * @param  array<string, Account>  $accounts
     * @return list<Person>
     */
    private function people(Workspace $workspace, User $user, array $accounts): array
    {
        $people = [];

        foreach ($this->personFixtures() as $fixture) {
            $account = $fixture['account'] !== null ? $accounts[$fixture['account']] : null;
            $email = $fixture['email'];
            $people[] = Person::query()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'email_normalized' => EmailNormalizer::normalize($email),
                ],
                [
                    'account_id' => $account?->id,
                    'full_name' => $fixture['name'],
                    'first_name' => EmailNormalizer::firstName($fixture['name']),
                    'email_address' => $email,
                    'job_title' => $fixture['title'],
                    'notes' => $fixture['notes'],
                    'tags' => $fixture['tags'],
                    'source' => $fixture['source'],
                    'created_by_user_id' => $user->id,
                ],
            );
        }

        return $people;
    }

    private function suppressions(Workspace $workspace, User $user): void
    {
        foreach ($this->suppressionFixtures() as $fixture) {
            SuppressionEntry::query()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'email_normalized' => EmailNormalizer::normalize($fixture['email']),
                ],
                [
                    'user_id' => $user->id,
                    'email_address' => $fixture['email'],
                    'reason' => $fixture['reason'],
                ],
            );
        }
    }

    private function campaign(User $user, Workspace $workspace): Campaign
    {
        $campaign = Campaign::query()
            ->where('workspace_id', $workspace->id)
            ->where('name', self::CAMPAIGN_NAME)
            ->first();

        if (! $campaign instanceof Campaign) {
            $campaign = app(CreateCampaignService::class)->handle($user, self::CAMPAIGN_NAME);
        }

        $mailboxes = Mailbox::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->get();

        if ($mailboxes->isNotEmpty()) {
            $campaign->mailboxes()->sync($mailboxes->values()->mapWithKeys(
                fn (Mailbox $mailbox, int $position) => [$mailbox->id => ['position' => $position + 1]],
            )->all());
        }

        return $campaign->fresh(['draftVersion.activeSteps', 'mailboxes']) ?? $campaign;
    }

    private function sequence(Campaign $campaign): void
    {
        $draftId = $campaign->getAttribute('draft_sequence_version_id');

        if (! is_numeric($draftId)) {
            return;
        }

        $stepCount = SequenceStep::query()
            ->where('sequence_version_id', $draftId)
            ->whereNull('deleted_at')
            ->count();

        if ($stepCount >= 2) {
            return;
        }

        app(SequenceStepEditor::class)->add($campaign, 'Follow-up');
    }

    /**
     * @param  list<Person>  $people
     */
    private function enroll(Campaign $campaign, array $people): void
    {
        $suppressed = collect($this->suppressionFixtures())
            ->pluck('email')
            ->map(fn (string $email): string => EmailNormalizer::normalize($email))
            ->flip();

        $eligible = Person::query()
            ->whereIn('id', array_map(fn (Person $person): int => $person->id, $people))
            ->with('account:id,name')
            ->get()
            ->filter(fn (Person $person): bool => ! $suppressed->has($person->email_normalized) && $person->account_id !== null)
            ->values()
            ->all();

        $candidates = array_map(fn (Person $person): EnrollmentCandidate => new EnrollmentCandidate(
            personId: $person->id,
            accountId: $person->account_id !== null ? (int) $person->account_id : null,
            emailAddress: $person->email_address,
            emailNormalized: $person->email_normalized,
            fullName: $person->full_name,
            firstName: $person->first_name ?: EmailNormalizer::firstName($person->full_name),
            company: $person->account?->name,
        ), array_slice($eligible, 0, 8));

        if ($candidates === []) {
            return;
        }

        DB::transaction(function () use ($campaign, $candidates): void {
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            app(EnrollPeopleInCampaign::class)->handle($locked, $candidates);
        });
    }

    /**
     * @return list<array{name: string, domain: string, notes: string, tags: list<string>, source: string}>
     */
    private function accountFixtures(): array
    {
        return [
            ['name' => 'Northstar Labs', 'domain' => 'northstar-labs.workflow.demo', 'notes' => 'Design-led SaaS.', 'tags' => ['saas', 'design'], 'source' => Account::SOURCE_MANUAL],
            ['name' => 'Harbor Analytics', 'domain' => 'harbor-analytics.workflow.demo', 'notes' => 'Revops tooling.', 'tags' => ['saas', 'gtm'], 'source' => Account::SOURCE_IMPORT],
            ['name' => 'Pinecone Freight', 'domain' => 'pinecone-freight.workflow.demo', 'notes' => 'Logistics operator.', 'tags' => ['ops'], 'source' => Account::SOURCE_MANUAL],
            ['name' => 'Lumen Health', 'domain' => 'lumen-health.workflow.demo', 'notes' => 'Clinic network.', 'tags' => ['health'], 'source' => Account::SOURCE_IMPORT],
            ['name' => 'Cascade Books', 'domain' => 'cascade-books.workflow.demo', 'notes' => 'Independent publisher.', 'tags' => ['media'], 'source' => Account::SOURCE_MANUAL],
            ['name' => 'Atlas Robotics', 'domain' => 'atlas-robotics.workflow.demo', 'notes' => 'Warehouse automation.', 'tags' => ['ops', 'saas'], 'source' => Account::SOURCE_IMPORT],
        ];
    }

    /**
     * @return list<array{name: string, email: string, title: string, account: string|null, notes: ?string, tags: list<string>, source: string}>
     */
    private function personFixtures(): array
    {
        return [
            ['name' => 'Priya Shah', 'email' => 'priya@northstar-labs.workflow.demo', 'title' => 'Founder', 'account' => 'northstar-labs.workflow.demo', 'notes' => 'Met at SaaStr.', 'tags' => ['saas'], 'source' => Person::SOURCE_MANUAL],
            ['name' => 'Owen Blake', 'email' => 'owen@northstar-labs.workflow.demo', 'title' => 'Head of Design', 'account' => 'northstar-labs.workflow.demo', 'notes' => null, 'tags' => ['design'], 'source' => Person::SOURCE_IMPORT],
            ['name' => 'Imani Cole', 'email' => 'imani@northstar-labs.workflow.demo', 'title' => 'GTM Lead', 'account' => 'northstar-labs.workflow.demo', 'notes' => null, 'tags' => ['gtm'], 'source' => Person::SOURCE_MANUAL],
            ['name' => 'Noah Berg', 'email' => 'noah@harbor-analytics.workflow.demo', 'title' => 'CEO', 'account' => 'harbor-analytics.workflow.demo', 'notes' => 'Asked for a follow-up.', 'tags' => ['saas'], 'source' => Person::SOURCE_IMPORT],
            ['name' => 'Elena Voss', 'email' => 'elena@harbor-analytics.workflow.demo', 'title' => 'VP Sales', 'account' => 'harbor-analytics.workflow.demo', 'notes' => null, 'tags' => ['gtm'], 'source' => Person::SOURCE_MANUAL],
            ['name' => 'Chris Adeyemi', 'email' => 'chris@harbor-analytics.workflow.demo', 'title' => 'Revops', 'account' => 'harbor-analytics.workflow.demo', 'notes' => null, 'tags' => ['ops'], 'source' => Person::SOURCE_IMPORT],
            ['name' => 'Sofia Nguyen', 'email' => 'sofia@pinecone-freight.workflow.demo', 'title' => 'COO', 'account' => 'pinecone-freight.workflow.demo', 'notes' => null, 'tags' => ['ops'], 'source' => Person::SOURCE_MANUAL],
            ['name' => 'Marcus Hill', 'email' => 'marcus@pinecone-freight.workflow.demo', 'title' => 'Fleet Lead', 'account' => 'pinecone-freight.workflow.demo', 'notes' => null, 'tags' => ['ops'], 'source' => Person::SOURCE_IMPORT],
            ['name' => 'Dana Ruiz', 'email' => 'dana@pinecone-freight.workflow.demo', 'title' => 'Buyer', 'account' => 'pinecone-freight.workflow.demo', 'notes' => null, 'tags' => ['gtm'], 'source' => Person::SOURCE_MANUAL],
            ['name' => 'Lea Okonkwo', 'email' => 'lea@lumen-health.workflow.demo', 'title' => 'Practice Lead', 'account' => 'lumen-health.workflow.demo', 'notes' => 'Asked to be removed.', 'tags' => ['health'], 'source' => Person::SOURCE_IMPORT],
            ['name' => 'Jonah Patel', 'email' => 'jonah@lumen-health.workflow.demo', 'title' => 'Ops Manager', 'account' => 'lumen-health.workflow.demo', 'notes' => null, 'tags' => ['ops'], 'source' => Person::SOURCE_MANUAL],
            ['name' => 'Rita Gomez', 'email' => 'rita@lumen-health.workflow.demo', 'title' => 'Founder', 'account' => 'lumen-health.workflow.demo', 'notes' => null, 'tags' => ['health'], 'source' => Person::SOURCE_IMPORT],
            ['name' => 'Hugo Lane', 'email' => 'hugo@cascade-books.workflow.demo', 'title' => 'Publisher', 'account' => 'cascade-books.workflow.demo', 'notes' => null, 'tags' => ['media'], 'source' => Person::SOURCE_MANUAL],
            ['name' => 'Amelia Cho', 'email' => 'amelia@cascade-books.workflow.demo', 'title' => 'Editor', 'account' => 'cascade-books.workflow.demo', 'notes' => null, 'tags' => ['media'], 'source' => Person::SOURCE_IMPORT],
            ['name' => 'Felix Ward', 'email' => 'felix@cascade-books.workflow.demo', 'title' => 'Marketing', 'account' => 'cascade-books.workflow.demo', 'notes' => null, 'tags' => ['gtm'], 'source' => Person::SOURCE_MANUAL],
            ['name' => 'Nia Brooks', 'email' => 'nia@atlas-robotics.workflow.demo', 'title' => 'CTO', 'account' => 'atlas-robotics.workflow.demo', 'notes' => null, 'tags' => ['saas'], 'source' => Person::SOURCE_IMPORT],
            ['name' => 'Spam Trap', 'email' => 'spam@atlas-robotics.workflow.demo', 'title' => 'Unknown', 'account' => 'atlas-robotics.workflow.demo', 'notes' => 'Do not email.', 'tags' => [], 'source' => Person::SOURCE_MANUAL],
            ['name' => 'Ira Bennett', 'email' => 'ira@freelance.workflow.demo', 'title' => 'Advisor', 'account' => null, 'notes' => 'Independent.', 'tags' => ['gtm'], 'source' => Person::SOURCE_MANUAL],
            ['name' => 'Keiko Mori', 'email' => 'keiko@freelance.workflow.demo', 'title' => 'Writer', 'account' => null, 'notes' => null, 'tags' => ['media'], 'source' => Person::SOURCE_IMPORT],
            ['name' => 'Sam Ortega', 'email' => 'sam@freelance.workflow.demo', 'title' => 'Consultant', 'account' => null, 'notes' => null, 'tags' => ['ops'], 'source' => Person::SOURCE_MANUAL],
        ];
    }

    /**
     * @return list<array{email: string, reason: SuppressionReason}>
     */
    private function suppressionFixtures(): array
    {
        return [
            ['email' => 'lea@lumen-health.workflow.demo', 'reason' => SuppressionReason::Unsubscribe],
            ['email' => 'spam@atlas-robotics.workflow.demo', 'reason' => SuppressionReason::Manual],
        ];
    }
}
