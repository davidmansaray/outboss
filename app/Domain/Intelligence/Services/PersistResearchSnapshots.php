<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Ai\DTOs\ResearchResult;
use App\Domain\Gtm\Support\PublicWebsiteUrl;
use App\Domain\Intelligence\DTOs\ResearchSnapshotInput;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\Account;
use App\Models\AccountResearchRun;
use App\Models\AccountResearchStage;
use App\Models\ResearchSourceSnapshot;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Persist bounded provider observations as immutable source snapshots. */
final class PersistResearchSnapshots
{
    /**
     * @param  ResearchResult|list<ResearchSnapshotInput|array<string,mixed>>  $results
     * @return list<ResearchSourceSnapshot>
     */
    public function handle(
        Workspace $workspace,
        AccountResearchRun $run,
        AccountResearchStage $stage,
        ResearchResult|array $results,
        ?string $providerOperationId = null,
    ): array {
        $this->assertScope($workspace, $run, $stage);
        $items = $results instanceof ResearchResult
            ? $this->fromProviderResult($results, ModelAttribute::enumValue($stage->stage_key) ?? 'research')
            : $results;
        if (count($items) > 100) {
            throw new RuntimeException('The source snapshot result set is too large.');
        }

        return DB::transaction(function () use ($workspace, $run, $stage, $items, $providerOperationId): array {
            $snapshots = [];
            foreach ($items as $index => $item) {
                $input = $item instanceof ResearchSnapshotInput ? $item : $this->inputFromArray($item, $stage, (int) $index);
                $content = $input->content;
                if ($content !== null && strlen($content) > (int) config('intelligence.research.max_snapshot_bytes', 128 * 1024)) {
                    $content = substr($content, 0, (int) config('intelligence.research.max_snapshot_bytes', 128 * 1024));
                    $state = 'partial';
                } else {
                    $state = $input->state;
                }
                $url = $this->safeUrl($input->canonicalUrl);
                $contentHash = hash('sha256', $content ?? '');
                $identity = $input->resultIdentity
                    ?? hash('sha256', implode('|', [(string) ($input->providerRequestId ?? ''), (string) ($input->providerItemRank ?? $index), (string) ($url ?? ''), $contentHash]));
                $existing = ResearchSourceSnapshot::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('research_run_id', $run->id)
                    ->where('result_identity', $identity)
                    ->first();
                if ($existing !== null) {
                    $snapshots[] = $existing;

                    continue;
                }
                $observedAt = $input->observedAt ?? now()->toIso8601String();
                $retrievedAt = $input->retrievedAt ?? now()->toIso8601String();
                $expiresAt = $input->expiresAt ?? now()->addHours((int) (ModelAttribute::array($run->freshness_policy)['snapshot_max_age_hours'] ?? 168))->toIso8601String();
                $snapshot = ResearchSourceSnapshot::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'account_id' => $run->account_id,
                    'research_run_id' => $run->id,
                    'stage_id' => $stage->id,
                    'source_type' => $input->sourceType,
                    'canonical_public_url' => $url,
                    'registrable_domain' => $input->registrableDomain ?? $this->registrableDomain($url),
                    'retrieved_at' => $retrievedAt,
                    'observed_at' => $observedAt,
                    'content_hash' => $contentHash,
                    'content' => $content,
                    'payload_reference' => $input->metadata['payload_reference'] ?? null,
                    'mime_type' => $input->mimeType,
                    'byte_count' => $content === null ? 0 : strlen($content),
                    'language' => $input->language,
                    'retrieval_capability' => $input->metadata['retrieval_capability'] ?? 'research.extract',
                    'retrieval_version' => 1,
                    'provider_operation_id' => $input->providerOperationId ?? $providerOperationId,
                    'provider_request_id' => $input->providerRequestId,
                    'provider_item_rank' => $input->providerItemRank ?? $index,
                    'retrieval_policy_hash' => ModelAttribute::array($run->policy_snapshot)['source_policy_hash'] ?? null,
                    'display_classification' => $input->metadata['display_classification'] ?? 'standard',
                    'llm_use_classification' => $input->metadata['llm_use_classification'] ?? 'allowed',
                    'export_classification' => $input->metadata['export_classification'] ?? 'standard',
                    'retention_classification' => $input->metadata['retention_classification'] ?? 'standard',
                    'expires_at' => $expiresAt,
                    'redacted_at' => null,
                    'state' => $state,
                    'safe_failure_code' => $state === 'failed' ? ($input->metadata['safe_failure_code'] ?? 'provider_failed') : null,
                    'result_identity' => $identity,
                ]);
                $snapshots[] = $snapshot;
            }

            return $snapshots;
        }, 3);
    }

    public function recordFailure(
        Workspace $workspace,
        AccountResearchRun $run,
        AccountResearchStage $stage,
        string $safeFailureCode,
        ?string $providerOperationId = null,
    ): ResearchSourceSnapshot {
        return $this->handle($workspace, $run, $stage, [new ResearchSnapshotInput(
            sourceType: ModelAttribute::enumValue($stage->stage_key) ?? 'research',
            canonicalUrl: null,
            registrableDomain: null,
            content: null,
            providerOperationId: $providerOperationId,
            state: 'failed',
            metadata: ['safe_failure_code' => $safeFailureCode],
            resultIdentity: hash('sha256', $run->id.'|'.$stage->id.'|'.$safeFailureCode),
        )], $providerOperationId)[0];
    }

    /** @return list<ResearchSnapshotInput> */
    private function fromProviderResult(ResearchResult $result, string $stageKey): array
    {
        return array_map(static function (array $item, int $index) use ($result, $stageKey): ResearchSnapshotInput {
            $content = $item['raw_content'] ?? $item['content'] ?? $item['snippet'] ?? null;

            return new ResearchSnapshotInput(
                sourceType: $stageKey === 'targeted_search' ? 'tavily_search' : 'tavily_extract',
                canonicalUrl: is_string($item['url'] ?? null) ? $item['url'] : null,
                registrableDomain: null,
                content: is_string($content) ? $content : null,
                mimeType: is_string($item['mime_type'] ?? null) ? $item['mime_type'] : null,
                providerRequestId: $result->providerRequestId,
                providerItemRank: $index,
                metadata: [
                    'title' => is_string($item['title'] ?? null) ? mb_substr($item['title'], 0, 512) : null,
                    'retrieval_capability' => $stageKey === 'targeted_search' ? 'research.search' : 'research.extract',
                ],
            );
        }, $result->results, array_keys($result->results));
    }

    /** @param array<string,mixed> $item */
    private function inputFromArray(array $item, AccountResearchStage $stage, int $index): ResearchSnapshotInput
    {
        return new ResearchSnapshotInput(
            sourceType: (string) ($item['source_type'] ?? $item['sourceType'] ?? (ModelAttribute::enumValue($stage->stage_key) ?? 'research')),
            canonicalUrl: isset($item['canonical_public_url']) ? (string) $item['canonical_public_url'] : (isset($item['url']) ? (string) $item['url'] : null),
            registrableDomain: isset($item['registrable_domain']) ? (string) $item['registrable_domain'] : null,
            content: isset($item['content']) ? (string) $item['content'] : (isset($item['raw_content']) ? (string) $item['raw_content'] : null),
            mimeType: isset($item['mime_type']) ? (string) $item['mime_type'] : null,
            language: isset($item['language']) ? (string) $item['language'] : null,
            providerOperationId: isset($item['provider_operation_id']) ? (string) $item['provider_operation_id'] : null,
            providerRequestId: isset($item['provider_request_id']) ? (string) $item['provider_request_id'] : null,
            providerItemRank: isset($item['provider_item_rank']) ? (int) $item['provider_item_rank'] : $index,
            state: (string) ($item['state'] ?? 'available'),
            metadata: is_array($item['metadata'] ?? null) ? $item['metadata'] : [],
            resultIdentity: isset($item['result_identity']) ? (string) $item['result_identity'] : null,
            observedAt: isset($item['observed_at']) ? (string) $item['observed_at'] : null,
            retrievedAt: isset($item['retrieved_at']) ? (string) $item['retrieved_at'] : null,
            expiresAt: isset($item['expires_at']) ? (string) $item['expires_at'] : null,
        );
    }

    private function assertScope(Workspace $workspace, AccountResearchRun $run, AccountResearchStage $stage): void
    {
        if ((int) $run->workspace_id !== (int) $workspace->id
            || (int) $stage->workspace_id !== (int) $workspace->id
            || (string) $stage->research_run_id !== (string) $run->id) {
            throw new RuntimeException('The research snapshot scope is invalid.');
        }
        if (! Account::query()->where('workspace_id', $workspace->id)->whereKey($run->account_id)->exists()) {
            throw new RuntimeException('The Account does not belong to the research workspace.');
        }
    }

    private function safeUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        try {
            return PublicWebsiteUrl::normalize($url);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('The source URL failed the public URL policy.', 0, $exception);
        }
    }

    private function registrableDomain(?string $url): ?string
    {
        $host = $url === null ? null : parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        $parts = explode('.', trim($host, '.'));

        return count($parts) < 2 ? $host : implode('.', array_slice($parts, -2));
    }
}
