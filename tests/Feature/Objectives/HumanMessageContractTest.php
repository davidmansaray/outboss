<?php

use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Exceptions\DraftMessageValidationException;
use App\Domain\Objectives\Support\ApprovalManifest;
use App\Domain\Objectives\Support\HumanMessageContract;
use App\Models\ExecutionPlan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

function humanMessageProvenance(): array
{
    return ['composition_id' => (string) Str::uuid(), 'goal_version_id' => (string) Str::uuid(),
        'offer_version_id' => (string) Str::uuid(), 'offer_content_hash' => str_repeat('a', 64),
        'author_id' => 1, 'evidence_manifest_hash' => str_repeat('b', 64)];
}

it('preserves short human copy and supports a reply CTA without a booking link', function () {
    $contract = app(HumanMessageContract::class);
    $result = $contract->build('A question for {{company}}', 'Hi {{first_name}}, interested in a consultation?',
        ['first_name' => 'Sam', 'company' => 'Acme'], 'https://example.com/book', humanMessageProvenance());
    expect($result['subject'])->toBe('A question for Acme');
    expect($result['body'])->toBe('Hi Sam, interested in a consultation?');
    expect(strlen($result['provenance_hash']))->toBe(64);
});

it('binds exact content to its author and selected versions', function () {
    $contract = app(HumanMessageContract::class);
    $provenance = humanMessageProvenance();
    $build = fn (array $context) => $contract->build('Consultation', 'Book here: https://example.com/book',
        ['first_name' => 'Sam', 'company' => 'Acme'], 'https://example.com/book', $context);
    $first = $build($provenance);
    expect($build($provenance))->toBe($first);
    $changed = $build(array_replace($provenance, ['author_id' => 2]));
    expect($changed['content_hash'])->toBe($first['content_hash']);
    expect($changed['provenance_hash'])->not->toBe($first['provenance_hash']);
});

it('rejects unsafe or unapproved human content', function (string $subject, string $body) {
    expect(fn () => app(HumanMessageContract::class)->build($subject, $body,
        ['first_name' => 'Sam', 'company' => 'Acme'], 'https://example.com/book', humanMessageProvenance()))
        ->toThrow(DraftMessageValidationException::class);
})->with([
    ["Hello\r\nBcc: other@example.com", 'Interested?'],
    ['Hello', '<a href="https://evil.example">Book</a>'],
    ['Hello', 'Book at https://evil.example'],
    ['Hello', '{Hello|Hi} {{first_name}}'],
    ['Hello', 'Hi {{unknown}}'],
    ['Hello', "Interested?\nBcc: other@example.com"],
    ['https://example.com/book', 'Interested?'],
]);

it('uses a distinct human approval display without rewriting legacy manifests', function () {
    $plan = new ExecutionPlan;
    $plan->forceFill(['id' => (string) Str::uuid(), 'workspace_id' => 1, 'objective_run_id' => (string) Str::uuid()]);
    $builder = app(ApprovalManifest::class);
    $legacy = $builder->build($plan, new Collection);
    expect($legacy['version'])->toBe(ApprovalManifest::VERSION);
    expect($legacy)->not->toHaveKey('authorship');
    $plan->forceFill(['origin' => ExecutionOrigin::Human]);
    $human = $builder->build($plan, new Collection);
    expect($human['version'])->toBe(ApprovalManifest::HUMAN_VERSION);
    expect($human['origin'])->toBe('human');
    expect($human['authorship']['objective_run_id'])->toBe($plan->objective_run_id);
    // Missing provenance is visible, never manufactured as successful authorship.
    expect($human['authorship']['campaign_composition_id'])->toBeNull();
    expect($human['authorship']['drafts'])->toBe([]);
});
