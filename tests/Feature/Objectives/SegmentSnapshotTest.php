<?php

use App\Domain\Intelligence\Support\CanonicalIntelligence;
use App\Domain\Objectives\Enums\AudienceMemberResult;
use App\Domain\Objectives\Services\CreateSegment;
use App\Domain\Objectives\Services\EvaluateSegment;
use App\Domain\Objectives\Services\FreezeAudienceSnapshot;
use App\Domain\Objectives\Support\AudiencePreviewToken;
use App\Domain\People\Services\AccountEditor;
use App\Domain\People\Services\DeleteAccount;
use App\Domain\People\Services\PersonEditor;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\DeleteProspectSourceData;
use App\Models\AccountResearchRun;
use App\Models\AudienceSnapshot;
use App\Models\AudienceSnapshotMember;
use App\Models\ProspectSource;
use App\Models\ResearchClaim;
use App\Models\SegmentVersion;
use App\Models\SuppressionEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use LogicException;

it('previews and freezes an immutable audience with explicit included uncertain and suppressed outcomes', function (): void {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    app(CreateProspectSource::class)->ensureSystemSource($workspace, 'manual', $user);
    $account = app(AccountEditor::class)->create($workspace, $user, [
        'name' => 'Acme', 'website' => 'https://acme.example', 'source' => 'manual',
    ]);
    $included = app(PersonEditor::class)->create($workspace, $user, [
        'account_id' => $account->id,
        'full_name' => 'Rae Included',
        'email' => 'rae@acme.example',
        'job_title' => 'VP Revenue Operations',
        'source' => 'manual',
    ]);
    $uncertain = app(PersonEditor::class)->create($workspace, $user, [
        'account_id' => $account->id,
        'full_name' => 'Morgan Unknown',
        'email' => 'morgan@acme.example',
        'job_title' => null,
        'source' => 'manual',
    ]);
    $suppressed = app(PersonEditor::class)->create($workspace, $user, [
        'account_id' => $account->id,
        'full_name' => 'Sam Suppressed',
        'email' => 'sam@acme.example',
        'job_title' => 'Revenue Director',
        'source' => 'manual',
    ]);
    SuppressionEntry::query()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'email_address' => 'sam@acme.example',
        'email_normalized' => 'sam@acme.example',
        'reason' => 'manual',
    ]);
    $segment = app(CreateSegment::class)->handle(
        $workspace,
        $user,
        'Revenue leaders',
        ['field' => 'person.job_title', 'operator' => 'contains_normalized', 'value' => 'revenue'],
    );
    $version = $segment->currentVersion;
    $preview = app(EvaluateSegment::class)->handle($workspace, $version);
    $token = app(AudiencePreviewToken::class)->issue((int) $workspace->id, $version->id, $preview->evaluationHash);

    $snapshot = app(FreezeAudienceSnapshot::class)->handle(
        $workspace,
        $version,
        $user,
        $preview->evaluationHash,
        $token,
    );
    $replay = app(FreezeAudienceSnapshot::class)->handle(
        $workspace,
        $version,
        $user,
        $preview->evaluationHash,
        $token,
    );

    expect($snapshot->id)->toBe($replay->id)
        ->and($snapshot->included_people_count)->toBe(1)
        ->and($snapshot->uncertain_people_count)->toBe(1)
        ->and($snapshot->suppressed_people_count)->toBe(1)
        ->and($snapshot->members()->where('person_id', $included->id)->firstOrFail()->result)->toBe(AudienceMemberResult::Included)
        ->and($snapshot->members()->where('person_id', $uncertain->id)->firstOrFail()->result)->toBe(AudienceMemberResult::Uncertain)
        ->and($snapshot->members()->where('person_id', $suppressed->id)->firstOrFail()->result)->toBe(AudienceMemberResult::Suppressed)
        ->and(AudienceSnapshot::query()->count())->toBe(1)
        ->and(AudienceSnapshotMember::query()->count())->toBe(3);

    expect(fn () => $snapshot->forceFill(['evaluation_hash' => str_repeat('0', 64)])->save())
        ->toThrow(LogicException::class);

    app(DeleteAccount::class)->handle($account, $user);
    $detached = AudienceSnapshotMember::query()->where('audience_snapshot_id', $snapshot->id)->get();
    expect($detached)->toHaveCount(3)
        ->and($detached->pluck('account_id')->filter())->toBeEmpty()
        ->and($detached->pluck('person_id')->filter())->toBeEmpty()
        ->and(AudienceSnapshot::query()->whereKey($snapshot->id)->exists())->toBeTrue();

    $source = ProspectSource::query()->where('workspace_id', $workspace->id)->where('system_key', 'manual')->firstOrFail();
    app(DeleteProspectSourceData::class)->handle($source);
    expect($snapshot->members()->firstOrFail()->sources()->firstOrFail()->rights_result)->toBe('source_deleted');
});

it('refuses a changed preview and cross-workspace segment version', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $segment = app(CreateSegment::class)->handle(
        $workspace,
        $owner,
        'Safe segment',
        ['field' => 'account.domain_present', 'operator' => 'eq', 'value' => true],
    );
    $preview = app(EvaluateSegment::class)->handle($workspace, $segment->currentVersion);
    $token = app(AudiencePreviewToken::class)->issue((int) $workspace->id, $segment->current_version_id, $preview->evaluationHash);

    expect(fn () => app(FreezeAudienceSnapshot::class)->handle(
        $workspace,
        $segment->currentVersion,
        $owner,
        str_repeat('0', 64),
        $token,
    ))->toThrow(ValidationException::class);

    $foreign = User::factory()->create()->currentWorkspace();
    expect(fn () => app(EvaluateSegment::class)->handle($foreign, SegmentVersion::query()->findOrFail($segment->current_version_id)))
        ->toThrow(ModelNotFoundException::class);
});

it('freezes only current nonsuperseded message-safe Claims with their exact safety evidence', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    app(CreateProspectSource::class)->ensureSystemSource($workspace, 'manual', $owner);
    $account = app(AccountEditor::class)->create($workspace, $owner, [
        'name' => 'Safe Evidence Co', 'website' => 'https://safe-evidence.example', 'source' => 'manual',
    ]);
    $person = app(PersonEditor::class)->create($workspace, $owner, [
        'account_id' => $account->id,
        'full_name' => 'Rae Safe',
        'email' => 'rae@safe-evidence.example',
        'job_title' => 'Revenue Director',
        'source' => 'manual',
    ]);
    $researchRun = AccountResearchRun::factory()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
    ]);
    $makeClaim = function (string $predicate, string $value, array $changes = []) use ($workspace, $account, $researchRun): ResearchClaim {
        return ResearchClaim::query()->create([
            'workspace_id' => $workspace->id,
            'account_id' => $account->id,
            'research_run_id' => $researchRun->id,
            'supersedes_claim_id' => null,
            'subject_type' => 'account',
            'predicate' => $predicate,
            'value_type' => 'string',
            'value_json' => $value,
            'value_hash' => CanonicalIntelligence::valueHash($value),
            'status' => 'owner_confirmed',
            'confidence' => 1,
            'calibration_band' => 'owner_confirmed',
            'observed_at' => now(),
            'fresh_until' => now()->addDay(),
            'expires_at' => now()->addDay(),
            'extractor_kind' => 'owner_confirmation',
            'extractor_version' => '1',
            'safe_for_message' => true,
            'content_hash' => hash('sha256', $predicate.'|'.$value.'|'.json_encode($changes)),
            ...$changes,
        ]);
    };
    $safe = $makeClaim('company.category', 'B2B software');
    $unsafe = $makeClaim('company.market', 'Mid-market', ['safe_for_message' => false]);
    $expired = $makeClaim('company.motion', 'Founder led', ['fresh_until' => now()->subMinute()]);
    $superseded = $makeClaim('company.positioning', 'Old positioning');
    $replacement = $makeClaim('company.positioning', 'Current positioning', [
        'supersedes_claim_id' => $superseded->id,
    ]);
    $segment = app(CreateSegment::class)->handle(
        $workspace,
        $owner,
        'Safe evidence audience',
        ['field' => 'person.job_title', 'operator' => 'contains_normalized', 'value' => 'revenue'],
    );
    $preview = app(EvaluateSegment::class)->handle($workspace, $segment->currentVersion);
    $token = app(AudiencePreviewToken::class)->issue(
        (int) $workspace->id,
        (string) $segment->current_version_id,
        $preview->evaluationHash,
    );
    $snapshot = app(FreezeAudienceSnapshot::class)->handle(
        $workspace,
        $segment->currentVersion,
        $owner,
        $preview->evaluationHash,
        $token,
    );
    $manifest = $snapshot->members()->where('person_id', $person->id)->firstOrFail()->evidence_manifest;

    $frozenIds = array_column($manifest, 'claim_id');
    $expectedIds = [$safe->id, $replacement->id];
    sort($frozenIds, SORT_STRING);
    sort($expectedIds, SORT_STRING);
    expect($frozenIds)->toBe($expectedIds)
        ->and($frozenIds)->not->toContain($unsafe->id, $expired->id, $superseded->id);
    foreach ($manifest as $frozen) {
        expect($frozen['workspace_id'])->toBe((int) $workspace->id)
            ->and($frozen['account_id'])->toBe((int) $account->id)
            ->and($frozen['status'])->toBe('owner_confirmed')
            ->and($frozen['safe_for_message'])->toBeTrue()
            ->and($frozen['current'])->toBeTrue()
            ->and($frozen['nonsuperseded'])->toBeTrue()
            ->and($frozen['prohibited'])->toBeFalse()
            ->and($frozen['safety_hash'])->toHaveLength(64);
    }
});
