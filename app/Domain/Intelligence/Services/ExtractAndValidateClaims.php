<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\DTOs\ResearchClaimInput;
use App\Domain\Intelligence\Exceptions\ClaimValidationException;
use App\Domain\Intelligence\Support\CanonicalIntelligence;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\AccountResearchRun;
use App\Models\AccountResearchStage;
use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvidence;
use App\Models\ResearchSourceSnapshot;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/** Validate closed claim output and attach every accepted fact to evidence. */
final class ExtractAndValidateClaims
{
    /**
     * @param  string|array<string,mixed>|list<ResearchClaimInput|array<string,mixed>>  $output
     * @param  list<ResearchSourceSnapshot>|null  $snapshots
     * @return list<ResearchClaim>
     */
    public function handle(
        Workspace $workspace,
        AccountResearchRun $run,
        AccountResearchStage $stage,
        string|array $output,
        ?array $snapshots = null,
    ): array {
        $this->assertScope($workspace, $run, $stage);
        $snapshotModels = $this->snapshotList($snapshots ?? ResearchSourceSnapshot::query()
            ->where('workspace_id', $workspace->id)
            ->where('research_run_id', $run->id)
            ->whereIn('state', ['available', 'partial'])
            ->orderBy('created_at')
            ->get()
            ->all());
        $inputs = $this->inputs($output);
        if (count($inputs) > (int) config('intelligence.research.max_claims_per_run', 100)) {
            throw new ClaimValidationException('The provider returned too many claims.');
        }
        $handles = [];
        foreach ($snapshotModels as $index => $snapshot) {
            if ((int) $snapshot->workspace_id !== (int) $workspace->id) {
                continue;
            }
            $handles['SRC_'.($index + 1)] = $snapshot;
            $handles[(string) $snapshot->id] = $snapshot;
        }

        return DB::transaction(function () use ($workspace, $run, $inputs, $handles): array {
            $claims = [];
            foreach ($inputs as $input) {
                $this->validateInput($input);
                $evidence = $this->evidence($input, $handles);
                if ($input->status === 'supported' && $evidence === []) {
                    throw new ClaimValidationException("Supported claim [{$input->predicate}] has no valid evidence.");
                }
                $valueHash = CanonicalIntelligence::valueHash($input->value);
                $contentHash = CanonicalIntelligence::hash([
                    'predicate' => $input->predicate,
                    'value_type' => $input->valueType,
                    'value' => $input->value,
                    'status' => $input->status,
                    'evidence' => array_map(static fn (array $edge): array => [
                        'snapshot_id' => $edge['snapshot_id'],
                        'edge_type' => $edge['edge_type'],
                        'locator_hash' => $edge['locator_hash'],
                    ], $evidence),
                ]);
                $existing = ResearchClaim::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('research_run_id', $run->id)
                    ->where('content_hash', $contentHash)
                    ->first();
                if ($existing !== null) {
                    $claims[] = $existing;

                    continue;
                }
                $claim = ResearchClaim::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'account_id' => $run->account_id,
                    'research_run_id' => $run->id,
                    'supersedes_claim_id' => null,
                    'subject_type' => 'account',
                    'predicate' => $input->predicate,
                    'value_type' => $input->valueType,
                    'value_json' => $input->value,
                    'value_hash' => $valueHash,
                    'status' => $input->status,
                    'confidence' => $input->confidence,
                    'calibration_band' => $input->calibrationBand,
                    'observed_at' => $input->observedAt ?? now(),
                    'fresh_until' => $input->freshUntil ?? now()->addHours((int) (ModelAttribute::array($run->freshness_policy)['claim_max_age_hours'] ?? 720)),
                    'expires_at' => $input->expiresAt,
                    'extractor_kind' => $input->extractorKind,
                    'extractor_version' => $input->extractorVersion,
                    'prompt_version' => $input->metadata['prompt_version'] ?? null,
                    'model_version' => $input->metadata['model_version'] ?? null,
                    'policy_version' => $input->metadata['policy_version'] ?? null,
                    'catalogue_version' => $input->metadata['catalogue_version'] ?? null,
                    'safe_for_message' => $input->safeForMessage && $input->status === 'owner_confirmed',
                    'content_hash' => $contentHash,
                ]);
                foreach ($evidence as $edge) {
                    ResearchClaimEvidence::query()->create([
                        'id' => (string) Str::uuid(),
                        'workspace_id' => $workspace->id,
                        'claim_id' => $claim->id,
                        'source_snapshot_id' => $edge['snapshot_id'],
                        'edge_type' => $edge['edge_type'],
                        'locator' => $edge['locator'],
                        'locator_hash' => $edge['locator_hash'],
                        'quote_hash' => $edge['quote_hash'],
                        'evidence_weight' => $edge['weight'],
                        'extraction_version' => $input->extractorVersion,
                    ]);
                }
                $claims[] = $claim;
            }

            return $claims;
        }, 3);
    }

    /**
     * Alias used by callers that name this boundary explicitly.
     *
     * @param  string|array<string,mixed>|list<ResearchClaimInput|array<string,mixed>>  $output
     * @param  list<ResearchSourceSnapshot>|null  $snapshots
     * @return list<ResearchClaim>
     */
    public function extract(
        Workspace $workspace,
        AccountResearchRun $run,
        AccountResearchStage $stage,
        string|array $output,
        ?array $snapshots = null,
    ): array {
        return $this->handle($workspace, $run, $stage, $output, $snapshots);
    }

    /**
     * @param  string|array<string,mixed>|list<ResearchClaimInput|array<string,mixed>>  $output
     * @return list<ResearchClaimInput>
     */
    private function inputs(string|array $output): array
    {
        if (is_string($output)) {
            try {
                $decoded = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new ClaimValidationException('The claim output was not valid JSON.', 0, $exception);
            }
            $output = is_array($decoded) ? $decoded : [];
        }
        if (isset($output['claims']) && is_array($output['claims'])) {
            $output = $output['claims'];
        }
        if (! array_is_list($output)) {
            throw new ClaimValidationException('The claim output must contain a list of claims.');
        }

        return array_map(static function (ResearchClaimInput|array $input): ResearchClaimInput {
            if ($input instanceof ResearchClaimInput) {
                return $input;
            }

            return new ResearchClaimInput(
                predicate: (string) ($input['predicate'] ?? ''),
                value: $input['value'] ?? null,
                valueType: (string) ($input['value_type'] ?? $input['valueType'] ?? ''),
                status: (string) ($input['status'] ?? 'supported'),
                confidence: (float) ($input['confidence'] ?? 0),
                calibrationBand: isset($input['calibration_band']) ? (string) $input['calibration_band'] : null,
                evidence: is_array($input['evidence'] ?? $input['citations'] ?? null) ? ($input['evidence'] ?? $input['citations']) : [],
                observedAt: isset($input['observed_at']) ? (string) $input['observed_at'] : null,
                freshUntil: isset($input['fresh_until']) ? (string) $input['fresh_until'] : null,
                expiresAt: isset($input['expires_at']) ? (string) $input['expires_at'] : null,
                extractorKind: (string) ($input['extractor_kind'] ?? 'llm'),
                extractorVersion: (string) ($input['extractor_version'] ?? '1'),
                safeForMessage: (bool) ($input['safe_for_message'] ?? false),
                metadata: is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
            );
        }, $output);
    }

    private function validateInput(ResearchClaimInput $input): void
    {
        if ($input->predicate === '' || strlen($input->predicate) > 160 || preg_match('/^[a-z][a-z0-9_.-]*$/', $input->predicate) !== 1) {
            throw new ClaimValidationException('The claim predicate is not an approved canonical key.');
        }
        if ($this->sensitivePredicate($input->predicate)) {
            throw new ClaimValidationException('Sensitive or prohibited claim predicates are not allowed.');
        }
        if (! in_array($input->valueType, ['string', 'integer', 'number', 'boolean', 'date', 'url', 'string_list', 'object'], true)) {
            throw new ClaimValidationException('The claim value type is invalid.');
        }
        if (! in_array($input->status, ['supported', 'inferred', 'unsupported'], true)) {
            throw new ClaimValidationException('Provider output cannot assign this claim status.');
        }
        if ($input->confidence < 0 || $input->confidence > 1) {
            throw new ClaimValidationException('Claim confidence must be between zero and one.');
        }
        $encoded = json_encode($input->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > 16_384) {
            throw new ClaimValidationException('The claim value exceeds its bounded size.');
        }
    }

    /**
     * @param  array<string,ResearchSourceSnapshot>  $handles
     * @return list<array{snapshot_id:string,edge_type:string,locator:?string,locator_hash:string,quote_hash:?string,weight:float}>
     */
    private function evidence(ResearchClaimInput $input, array $handles): array
    {
        $evidence = [];
        foreach ($this->evidenceItems($input->evidence) as $edge) {
            $handle = $edge['snapshot_id'] ?? $edge['source_id'] ?? $edge['source_handle'] ?? $edge['citation'] ?? null;
            $snapshot = is_string($handle) ? ($handles[$handle] ?? null) : null;
            if ($snapshot === null || ! in_array(ModelAttribute::enumValue($snapshot->state), ['available', 'partial'], true)) {
                throw new ClaimValidationException('A claim citation does not reference a supplied source snapshot.');
            }
            $edgeType = (string) ($edge['edge_type'] ?? 'supports');
            if (! in_array($edgeType, ['supports', 'contradicts'], true)) {
                throw new ClaimValidationException('The claim evidence edge type is invalid.');
            }
            $locator = isset($edge['locator']) ? mb_substr((string) $edge['locator'], 0, 500) : null;
            $quoteHash = isset($edge['quote_hash']) ? (string) $edge['quote_hash'] : null;
            $evidence[] = [
                'snapshot_id' => (string) $snapshot->id,
                'edge_type' => $edgeType,
                'locator' => $locator,
                'locator_hash' => CanonicalIntelligence::valueHash($locator ?? ''),
                'quote_hash' => $quoteHash,
                'weight' => max(0, min(1, (float) ($edge['weight'] ?? $edge['evidence_weight'] ?? 1))),
            ];
        }

        return $evidence;
    }

    /**
     * @param  array<int,mixed>  $snapshots
     * @return list<ResearchSourceSnapshot>
     */
    private function snapshotList(array $snapshots): array
    {
        $result = [];
        foreach ($snapshots as $snapshot) {
            if ($snapshot instanceof ResearchSourceSnapshot) {
                $result[] = $snapshot;
            }
        }

        return $result;
    }

    /**
     * @param  list<mixed>  $evidence
     * @return list<array<string,mixed>>
     */
    private function evidenceItems(array $evidence): array
    {
        $result = [];
        foreach ($evidence as $edge) {
            if (is_array($edge)) {
                $result[] = $edge;
            }
        }

        return $result;
    }

    private function sensitivePredicate(string $predicate): bool
    {
        return preg_match('/(?:race|religion|health|politic|gender|age|ethnic|income|disability|protected)/i', $predicate) === 1;
    }

    private function assertScope(Workspace $workspace, AccountResearchRun $run, AccountResearchStage $stage): void
    {
        if ((int) $run->workspace_id !== (int) $workspace->id
            || (int) $stage->workspace_id !== (int) $workspace->id
            || (string) $stage->research_run_id !== (string) $run->id) {
            throw new RuntimeException('The claim extraction scope is invalid.');
        }
    }
}
