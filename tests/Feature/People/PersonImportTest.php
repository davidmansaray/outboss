<?php

use App\Domain\Campaigns\Enums\ImportStatus;
use App\Models\Account;
use App\Models\Person;
use App\Models\PersonImport;
use App\Models\SuppressionEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

function previewPeopleImport(User $user, array $rows, array $mapping = []): PersonImport
{
    test()->actingAs($user)
        ->post(route('people.imports.preview'), [
            'rows' => $rows,
            'mapping' => array_merge([
                'email' => 'email',
                'name' => 'name',
                'job_title' => 'job_title',
                'company' => 'company',
                'tags' => 'tags',
            ], $mapping),
        ])
        ->assertRedirect();

    return PersonImport::query()
        ->where('workspace_id', $user->currentWorkspace()->id)
        ->latest('id')
        ->firstOrFail();
}

it('keeps source-producing person imports owner-only', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create(['type' => 'team']);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'role' => WorkspaceMembership::ROLE_OWNER,
    ]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->post(route('people.imports.preview'), [
            'rows' => [['email' => 'member@example.test']],
            'mapping' => ['email' => 'email'],
        ])
        ->assertForbidden();

    expect(PersonImport::query()->where('workspace_id', $workspace->id)->exists())->toBeFalse();
});

it('classifies preview rows into the five reported buckets', function () {
    $user = User::factory()->create();
    Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'email_address' => 'maya@acme.test',
        'email_normalized' => 'maya@acme.test',
        'full_name' => 'Maya',
        'notes' => 'Keep me',
    ]);
    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'email_address' => 'gone@acme.test',
        'email_normalized' => 'gone@acme.test',
    ]);

    $rows = [
        ['email' => 'new@northstar.studio', 'name' => 'New Person', 'company' => 'Northstar'],
        ['email' => 'maya@acme.test', 'name' => 'Maya Chen', 'company' => 'Acme'],
        ['email' => 'not-an-email', 'name' => 'Bad', 'company' => 'Acme'],
        ['email' => 'new@northstar.studio', 'name' => 'Duplicate', 'company' => 'Northstar'],
        ['email' => 'gone@acme.test', 'name' => 'Gone', 'company' => 'Acme'],
    ];

    $this->actingAs($user)
        ->from(route('prospects.index'))
        ->post(route('people.imports.preview'), [
            'rows' => $rows,
            'mapping' => [
                'email' => 'email',
                'name' => 'name',
                'job_title' => 'job_title',
                'company' => 'company',
                'tags' => 'tags',
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('personImport', fn (array $review): bool => $review['new'] === 1
            && $review['source_name'] === 'People import #1'
            && str_contains($review['policy_summary'], '365-day normalized retention')
            && $review['updated'] === 1
            && $review['invalid'] === 1
            && $review['duplicate'] === 1
            && $review['suppressed'] === 1
            && $review['samples']['new'][0]['email'] === 'new@northstar.studio');

    $import = PersonImport::query()
        ->where('workspace_id', $user->currentWorkspace()->id)
        ->latest('id')
        ->firstOrFail();

    expect($import->total)->toBe(5)
        ->and($import->new)->toBe(1)
        ->and($import->updated)->toBe(1)
        ->and($import->invalid)->toBe(1)
        ->and($import->duplicate)->toBe(1)
        ->and($import->suppressed)->toBe(1);
});

it('commits new and updated people, skips invalid and duplicate rows, and keeps suppressed people visible', function () {
    $user = User::factory()->create();
    $existing = Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'email_address' => 'maya@acme.test',
        'email_normalized' => 'maya@acme.test',
        'full_name' => 'Maya',
        'job_title' => 'Founder',
        'notes' => 'Keep me',
        'tags' => ['manual'],
        'source' => Person::SOURCE_MANUAL,
    ]);
    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'email_address' => 'gone@acme.test',
        'email_normalized' => 'gone@acme.test',
    ]);

    $import = previewPeopleImport($user, [
        ['email' => 'new@northstar.studio', 'name' => 'New Person', 'job_title' => 'AE', 'company' => 'Northstar', 'tags' => 'gtm'],
        ['email' => 'maya@acme.test', 'name' => 'Maya Chen', 'job_title' => '', 'company' => '', 'tags' => 'saas'],
        ['email' => 'not-an-email', 'name' => 'Bad', 'company' => 'Acme'],
        ['email' => 'new@northstar.studio', 'name' => 'Duplicate', 'company' => 'Northstar'],
        ['email' => 'gone@acme.test', 'name' => 'Gone Person', 'company' => 'Acme'],
    ]);

    $this->actingAs($user)
        ->post(route('people.imports.commit', $import), [
            'expected_mapping_version' => $import->mapping_version,
        ])
        ->assertRedirect();

    $existing->refresh();
    $created = Person::query()->where('email_normalized', 'new@northstar.studio')->first();
    $suppressed = Person::query()->where('email_normalized', 'gone@acme.test')->first();

    expect($import->fresh()->status)->toBe(ImportStatus::Completed)
        ->and($import->fresh()->imported)->toBe(3)
        ->and($created)->not->toBeNull()
        ->and($created->full_name)->toBe('New Person')
        ->and($created->source)->toBe(Person::SOURCE_IMPORT)
        ->and($existing->full_name)->toBe('Maya Chen')
        ->and($existing->job_title)->toBe('Founder')
        ->and($existing->notes)->toBe('Keep me')
        ->and($existing->tags)->toBe(['manual', 'saas'])
        ->and($existing->source)->toBe(Person::SOURCE_MANUAL)
        ->and($existing->account_id)->toBeNull()
        ->and($suppressed)->not->toBeNull()
        ->and(Person::query()->where('workspace_id', $user->currentWorkspace()->id)->count())->toBe(3);
});

it('matches and creates accounts during import without moving existing people', function () {
    $user = User::factory()->create();
    $existingAccount = Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'name' => 'Stripe',
        'domain_normalized' => 'stripe.com',
    ]);
    $named = Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'name' => 'Acme',
        'website' => null,
        'domain_normalized' => null,
    ]);
    $person = Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'account_id' => $named->id,
        'email_address' => 'keep@named.test',
        'email_normalized' => 'keep@named.test',
    ]);

    $import = previewPeopleImport($user, [
        ['email' => 'maya@stripe.com', 'name' => 'Maya', 'company' => 'Ignored'],
        ['email' => 'aisha@gmail.com', 'name' => 'Aisha', 'company' => 'acme'],
        ['email' => 'one@fresh.test', 'name' => 'One', 'company' => 'Fresh Co'],
        ['email' => 'two@fresh.test', 'name' => 'Two', 'company' => 'Fresh Co'],
        ['email' => 'keep@named.test', 'name' => 'Keep', 'company' => 'Fresh Co'],
        ['email' => 'free@gmail.com', 'name' => 'Free', 'company' => ''],
    ]);

    $this->actingAs($user)
        ->post(route('people.imports.commit', $import), [
            'expected_mapping_version' => $import->mapping_version,
        ])
        ->assertRedirect();

    expect(Person::query()->where('email_normalized', 'maya@stripe.com')->value('account_id'))->toBe($existingAccount->id)
        ->and(Person::query()->where('email_normalized', 'aisha@gmail.com')->value('account_id'))->toBe($named->id)
        ->and(Account::query()->where('workspace_id', $user->currentWorkspace()->id)->where('name', 'Fresh Co')->count())->toBe(1)
        ->and(Person::query()->where('email_normalized', 'one@fresh.test')->value('account_id'))
        ->toBe(Person::query()->where('email_normalized', 'two@fresh.test')->value('account_id'))
        ->and($person->fresh()->account_id)->toBe($named->id)
        ->and(Person::query()->where('email_normalized', 'free@gmail.com')->value('account_id'))->toBeNull()
        ->and(Account::query()->where('domain_normalized', 'gmail.com')->exists())->toBeFalse();
});

it('rejects a stale mapping version and a cross-workspace commit', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $import = previewPeopleImport($user, [
        ['email' => 'new@acme.test', 'name' => 'New', 'company' => 'Acme'],
    ]);

    $this->actingAs($user)
        ->post(route('people.imports.commit', $import), [
            'expected_mapping_version' => $import->mapping_version + 1,
        ])
        ->assertStatus(409);

    $this->actingAs($other)
        ->post(route('people.imports.commit', $import), [
            'expected_mapping_version' => $import->mapping_version,
        ])
        ->assertStatus(403);
});
