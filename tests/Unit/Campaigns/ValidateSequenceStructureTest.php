<?php

use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Services\ValidateSequenceStructure;
use App\Models\Campaign;
use App\Models\SequenceVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sequenceIssues(?SequenceVersion $version): array
{
    return collect(app(ValidateSequenceStructure::class)->handle($version))
        ->map(fn ($issue) => ['code' => $issue->code, 'message' => $issue->message])
        ->all();
}

it('returns no issues for a valid default campaign sequence', function () {
    $campaign = Campaign::factory()->create();

    expect(sequenceIssues($campaign->draftVersion))->toBe([]);
});

it('reports an empty sequence', function () {
    $campaign = Campaign::factory()->create();
    $campaign->draftVersion->activeSteps()->update(['deleted_at' => now()]);

    expect(sequenceIssues($campaign->fresh('draftVersion.activeSteps')->draftVersion))
        ->toBe([['code' => 'empty', 'message' => 'Add an initial email.']]);
});

it('collects every structural issue at once', function () {
    $campaign = Campaign::factory()->create();
    $step = $campaign->draftVersion->activeSteps->first();
    $step->forceFill([
        'delay_minutes' => 60,
        'send_as' => SequenceSendAs::Reply,
        'subject_template' => 'Hello {{unknown}}',
        'body_template' => '',
    ])->save();

    $issues = sequenceIssues($campaign->fresh('draftVersion.activeSteps')->draftVersion);
    $codes = array_column($issues, 'code');

    expect($codes)->toContain('first_step_not_immediate_new_thread')
        ->and($codes)->toContain('missing_body')
        ->and($codes)->toContain('reply_without_parent')
        ->and($codes)->toContain('unknown_variable')
        ->and(collect($issues)->firstWhere('code', 'first_step_not_immediate_new_thread')['message'])
        ->toBe('The first step must be an immediate new thread.')
        ->and(collect($issues)->firstWhere('code', 'missing_body')['message'])->toBe('Every step needs a body.')
        ->and(collect($issues)->firstWhere('code', 'reply_without_parent')['message'])
        ->toBe('Reply steps need an earlier new-thread step.')
        ->and(collect($issues)->firstWhere('code', 'unknown_variable')['message'])
        ->toBe('Unknown variable {{unknown}}.');
});

it('requires a subject on new-thread steps', function () {
    $campaign = Campaign::factory()->create();
    $campaign->draftVersion->activeSteps->first()->forceFill([
        'subject_template' => '',
        'body_template' => 'Hello',
    ])->save();

    expect(sequenceIssues($campaign->fresh('draftVersion.activeSteps')->draftVersion))
        ->toBe([['code' => 'missing_subject', 'message' => 'New-thread steps need a subject.']]);
});

it('treats a missing version as empty', function () {
    expect(sequenceIssues(null))->toBe([
        ['code' => 'empty', 'message' => 'Add an initial email.'],
    ]);
});
