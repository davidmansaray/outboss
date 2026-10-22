<?php

use App\Domain\Intelligence\Support\CanonicalIntelligence;
use App\Domain\Objectives\Support\MessageEvidenceContract;
use App\Models\Account;
use App\Models\AccountResearchRun;
use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvidence;
use App\Models\ResearchSourceSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $sourceChanges
 * @return array{workspace_id:int,account_id:int,claim:ResearchClaim,source:ResearchSourceSnapshot,evidence:ResearchClaimEvidence}
 */
function makeMessageEvidenceSafetyFixture(array $sourceChanges = []): array
{
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by_user_id' => $owner->id,
    ]);
    $run = AccountResearchRun::factory()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'state' => 'completed',
    ]);
    $value = 'Reviewed B2B software category';
    $source = ResearchSourceSnapshot::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'research_run_id' => $run->id,
        'stage_id' => null,
        'source_type' => 'website',
        'canonical_public_url' => 'https://evidence.example/source',
        'registrable_domain' => 'evidence.example',
        'retrieved_at' => now(),
        'observed_at' => now(),
        'content_hash' => hash('sha256', 'message-evidence-source'),
        'content' => $value,
        'mime_type' => 'text/plain',
        'byte_count' => strlen($value),
        'result_identity' => 'message-evidence-source',
        'expires_at' => now()->addDay(),
        'redacted_at' => null,
        'state' => 'available',
        ...$sourceChanges,
    ]);
    $claim = ResearchClaim::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'research_run_id' => $run->id,
        'supersedes_claim_id' => null,
        'subject_type' => 'account',
        'predicate' => 'company.category',
        'value_type' => 'string',
        'value_json' => $value,
        'value_hash' => CanonicalIntelligence::valueHash($value),
        'status' => 'supported',
        'confidence' => 1,
        'calibration_band' => 'high',
        'observed_at' => now(),
        'fresh_until' => now()->addDay(),
        'expires_at' => now()->addDay(),
        'extractor_kind' => 'deterministic_fixture',
        'extractor_version' => '1',
        'safe_for_message' => true,
        'content_hash' => hash('sha256', 'message-evidence-claim'),
    ]);
    $evidence = ResearchClaimEvidence::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'claim_id' => $claim->id,
        'source_snapshot_id' => $source->id,
        'edge_type' => 'supports',
        'locator' => 'Reviewed category',
        'locator_hash' => hash('sha256', 'Reviewed category'),
        'quote_hash' => hash('sha256', $value),
        'evidence_weight' => 1,
        'extraction_version' => '1',
    ]);

    return [
        'workspace_id' => (int) $workspace->id,
        'account_id' => (int) $account->id,
        'claim' => $claim,
        'source' => $source,
        'evidence' => $evidence,
    ];
}

it('fails the initial evidence freeze when a supported Claim has no usable source snapshot', function (string $condition): void {
    $sourceChanges = match ($condition) {
        'expired' => ['expires_at' => now()->subMinute()],
        'redacted' => ['redacted_at' => now()],
        'unavailable' => ['state' => 'restricted'],
        default => throw new InvalidArgumentException('Unknown source condition.'),
    };
    $fixture = makeMessageEvidenceSafetyFixture($sourceChanges);

    $manifest = app(MessageEvidenceContract::class)->currentManifest(
        $fixture['claim'],
        $fixture['workspace_id'],
        $fixture['account_id'],
    );

    expect($manifest)->toBeNull();
})->with(['expired', 'redacted', 'unavailable']);

it('keeps both established usable source states eligible for supported Claims', function (string $state): void {
    $fixture = makeMessageEvidenceSafetyFixture(['state' => $state]);

    $manifest = app(MessageEvidenceContract::class)->currentManifest(
        $fixture['claim'],
        $fixture['workspace_id'],
        $fixture['account_id'],
    );

    expect($manifest)->not->toBeNull()
        ->and(array_column($manifest['supporting_evidence'], 'evidence_id'))
        ->toBe([(string) $fixture['evidence']->id]);
})->with(['available', 'partial']);

it('includes only supporting evidence whose source snapshot is currently usable', function (): void {
    $fixture = makeMessageEvidenceSafetyFixture();
    $unusableSource = ResearchSourceSnapshot::query()->create([
        ...$fixture['source']->getAttributes(),
        'id' => (string) Str::uuid(),
        'content_hash' => hash('sha256', 'expired-message-evidence-source'),
        'result_identity' => 'expired-message-evidence-source',
        'expires_at' => now()->subMinute(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    ResearchClaimEvidence::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $fixture['workspace_id'],
        'claim_id' => $fixture['claim']->id,
        'source_snapshot_id' => $unusableSource->id,
        'edge_type' => 'supports',
        'locator' => 'Expired category',
        'locator_hash' => hash('sha256', 'Expired category'),
        'quote_hash' => hash('sha256', 'Reviewed B2B software category'),
        'evidence_weight' => 1,
        'extraction_version' => '1',
    ]);

    $manifest = app(MessageEvidenceContract::class)->currentManifest(
        $fixture['claim'],
        $fixture['workspace_id'],
        $fixture['account_id'],
    );

    expect($manifest)->not->toBeNull()
        ->and(array_column($manifest['supporting_evidence'], 'evidence_id'))
        ->toBe([(string) $fixture['evidence']->id]);
});

it('fails frozen evidence revalidation after its source expires or is redacted', function (string $condition): void {
    $fixture = makeMessageEvidenceSafetyFixture();
    $contract = app(MessageEvidenceContract::class);
    $frozen = $contract->currentManifest(
        $fixture['claim'],
        $fixture['workspace_id'],
        $fixture['account_id'],
    );
    expect($frozen)->not->toBeNull();

    DB::table('research_source_snapshots')
        ->where('id', $fixture['source']->id)
        ->update($condition === 'expired'
            ? ['expires_at' => now()->subMinute()]
            : ['redacted_at' => now()]);

    expect($contract->verifyFrozen(
        $frozen,
        $fixture['workspace_id'],
        $fixture['account_id'],
    ))->toBeNull();
})->with(['expired', 'redacted']);
