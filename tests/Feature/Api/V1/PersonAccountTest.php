<?php

use App\Domain\Api\TokenScope;
use App\Domain\People\Services\PersonEditor;
use App\Models\Account;
use App\Models\Person;
use App\Models\User;
use App\Models\WorkspaceMembership;

it('rejects people list requests without a token', function () {
    $this->getJson('/api/v1/people')
        ->assertUnauthorized()
        ->assertJsonPath('error.type', 'unauthenticated');
});

it('rejects people writes when the token only has people:read', function () {
    $user = User::factory()->create();

    $this->withToken(apiToken($user, [TokenScope::PeopleRead->value]))
        ->postJson('/api/v1/people', ['email' => 'maya@stripe.com'])
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');
});

it('lists, creates, shows, updates, and deletes a person', function () {
    $user = User::factory()->create();
    $token = apiToken($user, [TokenScope::PeopleRead->value, TokenScope::PeopleWrite->value]);

    $created = $this->withToken($token)
        ->postJson('/api/v1/people', [
            'email' => 'Maya@Stripe.com',
            'full_name' => 'Maya Chen',
        ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'Maya@Stripe.com')
        ->assertJsonPath('data.first_name', 'Maya');

    $id = $created->json('data.id');

    $this->withToken($token)
        ->getJson('/api/v1/people?query=maya')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $id);

    $this->withToken($token)
        ->getJson('/api/v1/people/'.$id)
        ->assertOk()
        ->assertJsonPath('data.full_name', 'Maya Chen');

    $this->withToken($token)
        ->patchJson('/api/v1/people/'.$id, [
            'email' => 'maya@new.test',
            'full_name' => 'Maya Chen',
        ])
        ->assertOk()
        ->assertJsonPath('data.email', 'maya@new.test');

    $this->withToken($token)
        ->deleteJson('/api/v1/people/'.$id)
        ->assertNoContent();
});

it('returns a validation error for an invalid person email', function () {
    $user = User::factory()->create();

    $this->withToken(apiToken($user, [TokenScope::PeopleWrite->value]))
        ->postJson('/api/v1/people', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'validation_error');
});

it('lets a workspace member delete a Person over REST when no Objective run or plan is affected', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $member = User::factory()->create();
    WorkspaceMembership::query()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);
    $person = Person::factory()->create(['workspace_id' => $workspace->id]);
    $newToken = $member->createToken('member-person-delete', [TokenScope::PeopleWrite->value]);
    $newToken->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

    $this->withToken($newToken->plainTextToken)
        ->deleteJson('/api/v1/people/'.$person->id)
        ->assertNoContent();

    app(PersonEditor::class)->delete($person, $member);
    expect(Person::query()->whereKey($person->id)->exists())->toBeFalse();
});

it('hides another workspace person', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $person = Person::factory()->create([
        'workspace_id' => $owner->currentWorkspace()->id,
    ]);

    $this->withToken(apiToken($intruder, [TokenScope::PeopleRead->value]))
        ->getJson('/api/v1/people/'.$person->id)
        ->assertForbidden();
});

it('creates, lists, and updates an account', function () {
    $user = User::factory()->create();
    $token = apiToken($user, [TokenScope::PeopleRead->value, TokenScope::PeopleWrite->value]);

    $created = $this->withToken($token)
        ->postJson('/api/v1/accounts', [
            'name' => 'Stripe',
            'website' => 'https://stripe.com',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Stripe')
        ->assertJsonPath('data.domain', 'stripe.com');

    $id = $created->json('data.id');

    $this->withToken($token)
        ->getJson('/api/v1/accounts?per_page=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->withToken($token)
        ->patchJson('/api/v1/accounts/'.$id, [
            'name' => 'Stripe Inc',
            'website' => 'https://stripe.com',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Stripe Inc');
});

it('hides another workspace account', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $account = Account::factory()->create([
        'workspace_id' => $owner->currentWorkspace()->id,
    ]);

    $this->withToken(apiToken($intruder, [TokenScope::PeopleRead->value]))
        ->getJson('/api/v1/accounts/'.$account->id)
        ->assertForbidden();
});
