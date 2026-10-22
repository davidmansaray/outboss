<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\ImportRowIssue;
use App\Domain\Campaigns\Enums\ImportSource;
use App\Domain\Campaigns\Enums\ImportStatus;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\RecipientImport;
use App\Models\SuppressionEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PreviewRecipientImport
{
    public function __construct(
        private FrozenMailExperimentInputGuard $frozenInputs,
        private GovernedCampaignInputGuard $governedInputs,
    ) {}

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array{email: string, name?: string, first_name?: string, company?: string}  $mapping
     */
    public function handle(User $user, Campaign $campaign, ImportSource $source, array $rows, array $mapping, ?string $filename = null): RecipientImport
    {
        $this->governedInputs->assertCampaignMutable($campaign);

        // Do not even persist a reviewable import for a frozen experiment
        // cohort.  The commit path repeats this check for imports created
        // before the freeze.
        $this->frozenInputs->assertCampaignAudienceMutable($campaign);

        $hash = hash('sha256', json_encode([$rows, $mapping]));
        $workspaceId = (int) $campaign->getAttribute('workspace_id');

        return DB::transaction(function () use ($user, $campaign, $source, $rows, $mapping, $filename, $hash, $workspaceId) {
            $import = RecipientImport::query()->create([
                'campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'source' => $source,
                'original_filename' => $filename,
                'source_hash' => $hash,
                'headers' => array_keys($rows[0] ?? ['email' => '']),
                'mapping' => $mapping,
                'mapping_version' => 1,
                'status' => ImportStatus::Previewed,
                'expires_at' => now()->addDay(),
            ]);

            $seen = [];
            $counts = ['total' => 0, 'ready' => 0, 'invalid' => 0, 'duplicate' => 0, 'suppressed' => 0];

            foreach ($rows as $index => $row) {
                $email = EmailNormalizer::normalize($row[$mapping['email']] ?? '');
                $name = trim((string) ($row[$mapping['name'] ?? 'name'] ?? ''));
                $mappedFirstName = trim((string) ($row[$mapping['first_name'] ?? ''] ?? ''));
                $company = trim((string) ($row[$mapping['company'] ?? 'company'] ?? ''));
                $issue = ImportRowIssue::Ready;

                if (! EmailNormalizer::isValid($email)) {
                    $issue = ImportRowIssue::Invalid;
                } elseif (isset($seen[$email]) || CampaignRecipient::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('campaign_id', $campaign->id)
                    ->where('email_normalized', $email)
                    ->exists()) {
                    $issue = ImportRowIssue::Duplicate;
                } elseif (SuppressionEntry::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('email_normalized', $email)
                    ->exists()) {
                    $issue = ImportRowIssue::Suppressed;
                }

                $seen[$email] = true;
                $counts['total']++;
                $counts[$issue->value]++;

                $import->rows()->create([
                    'row_number' => $index + 1,
                    'raw_values' => $row,
                    'email_address' => $email,
                    'email_normalized' => $email,
                    'full_name' => $name ?: null,
                    'first_name' => $mappedFirstName ?: EmailNormalizer::firstName($name),
                    'company' => $company ?: null,
                    'issue' => $issue,
                    'row_hash' => hash('sha256', $email.'|'.$index),
                ]);
            }

            $import->forceFill($counts)->save();

            return $import->fresh('rows');
        });
    }

    public function sample(): array
    {
        return [
            ['email' => 'maya@stripe.com', 'name' => 'Maya Chen', 'company' => 'Stripe'],
            ['email' => 'aisha@sequoia.com', 'name' => 'Aisha Rahman', 'company' => 'Sequoia'],
            ['email' => 'not-an-email', 'name' => 'Bad Row', 'company' => 'Acme'],
        ];
    }
}
