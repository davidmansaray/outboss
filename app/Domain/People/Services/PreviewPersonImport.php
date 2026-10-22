<?php

namespace App\Domain\People\Services;

use App\Domain\Campaigns\Enums\ImportStatus;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\Person;
use App\Models\PersonImport;
use App\Models\PersonImportRow;
use App\Models\SuppressionEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class PreviewPersonImport
{
    /**
     * @param  list<array<string, string>>  $rows
     * @param  array{email: string, name?: string, job_title?: string, company?: string, tags?: string}  $mapping
     */
    public function handle(User $user, Workspace $workspace, array $rows, array $mapping, ?string $filename = null): PersonImport
    {
        $hash = hash('sha256', json_encode([$rows, $mapping], JSON_THROW_ON_ERROR));
        $existingEmails = Person::query()
            ->where('workspace_id', $workspace->id)
            ->pluck('id', 'email_normalized');
        $suppressedEmails = SuppressionEntry::query()
            ->where('workspace_id', $workspace->id)
            ->pluck('email_normalized')
            ->filter()
            ->flip();

        return DB::transaction(function () use ($user, $workspace, $rows, $mapping, $filename, $hash, $existingEmails, $suppressedEmails) {
            $import = PersonImport::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'original_filename' => $filename,
                'source_hash' => $hash,
                'headers' => array_keys($rows[0] ?? ['email' => '']),
                'mapping' => $mapping,
                'mapping_version' => 1,
                'status' => ImportStatus::Previewed,
                'expires_at' => now()->addDay(),
            ]);

            $seen = [];
            $counts = [
                'total' => 0,
                'new' => 0,
                'updated' => 0,
                'invalid' => 0,
                'duplicate' => 0,
                'suppressed' => 0,
            ];

            foreach ($rows as $index => $row) {
                $emailRaw = trim((string) ($row[$mapping['email']] ?? ''));
                $email = EmailNormalizer::normalize($emailRaw);
                $name = trim((string) ($row[$mapping['name'] ?? 'name'] ?? ''));
                $jobTitle = trim((string) ($row[$mapping['job_title'] ?? 'job_title'] ?? ''));
                $company = trim((string) ($row[$mapping['company'] ?? 'company'] ?? ''));
                $tags = $this->parseTags((string) ($row[$mapping['tags'] ?? 'tags'] ?? ''));
                $issue = PersonImportRow::ISSUE_NEW;
                $action = PersonImportRow::ACTION_CREATE;

                if (! EmailNormalizer::isValid($emailRaw)) {
                    $issue = PersonImportRow::ISSUE_INVALID;
                    $action = PersonImportRow::ACTION_SKIP;
                } elseif (isset($seen[$email])) {
                    $issue = PersonImportRow::ISSUE_DUPLICATE;
                    $action = PersonImportRow::ACTION_SKIP;
                } elseif ($existingEmails->has($email)) {
                    $issue = PersonImportRow::ISSUE_UPDATED;
                    $action = PersonImportRow::ACTION_UPDATE;
                }

                if ($action !== PersonImportRow::ACTION_SKIP && $suppressedEmails->has($email)) {
                    $issue = PersonImportRow::ISSUE_SUPPRESSED;
                }

                $seen[$email] = true;
                $counts['total']++;
                $counts[$issue]++;

                $import->rows()->create([
                    'row_number' => $index + 1,
                    'raw_values' => $row,
                    'email_address' => $emailRaw !== '' ? $emailRaw : $email,
                    'email_normalized' => $email !== '' ? $email : null,
                    'full_name' => $name !== '' ? $name : null,
                    'first_name' => EmailNormalizer::firstName($name),
                    'job_title' => $jobTitle !== '' ? $jobTitle : null,
                    'company' => $company !== '' ? $company : null,
                    'tags' => $tags,
                    'issue' => $issue,
                    'action' => $action,
                    'row_hash' => hash('sha256', $email.'|'.$index),
                ]);
            }

            $import->forceFill($counts)->save();

            return $import->fresh('rows');
        });
    }

    /**
     * @return array<int, string>
     */
    private function parseTags(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $tags = [];

        foreach (preg_split('/[|,]/', $raw) ?: [] as $tag) {
            $value = trim($tag);

            if ($value !== '' && ! in_array($value, $tags, true)) {
                $tags[] = $value;
            }
        }

        return $tags;
    }
}
