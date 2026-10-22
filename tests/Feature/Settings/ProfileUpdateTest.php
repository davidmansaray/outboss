<?php

namespace Tests\Feature\Settings;

use App\Models\CanonicalFieldProvenance;
use App\Models\ExternalIdentity;
use App\Models\Person;
use App\Models\PersonSourceMembership;
use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use App\Models\ProviderConnection;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_account_deletion_purges_the_unshared_personal_workspace_source_data(): void
    {
        $user = User::factory()->create();
        $workspace = $user->workspaces()->where('workspaces.type', 'personal')->firstOrFail();
        $providerConnection = ProviderConnection::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
        ]);

        $source = ProspectSource::factory()->create(['workspace_id' => $workspace->id]);
        $version = ProspectSourceVersion::factory()->forSource($source)->create();
        $run = SourceRun::factory()->forSource($source, $version)->create();
        $source->forceFill([
            'current_version_id' => $version->id,
            'last_run_id' => $run->id,
        ])->save();

        $person = Person::factory()->create(['workspace_id' => $workspace->id]);
        $observation = SourceObservation::factory()
            ->forSourceRun($run)
            ->person()
            ->create([
                'person_id' => $person->id,
                'workspace_id' => $workspace->id,
            ]);
        $membership = PersonSourceMembership::factory()
            ->forObservation($observation, $person)
            ->create();
        $identity = ExternalIdentity::factory()->forPerson($person->id)->create([
            'workspace_id' => $workspace->id,
            'first_source_observation_id' => $observation->id,
            'last_source_observation_id' => $observation->id,
        ]);
        $provenance = CanonicalFieldProvenance::factory()->create([
            'workspace_id' => $workspace->id,
            'entity_type' => 'person',
            'account_id' => null,
            'person_id' => $person->id,
            'source_observation_id' => $observation->id,
            'source_run_id' => $run->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('home'));
        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
        $this->assertDatabaseMissing('provider_connections', ['id' => $providerConnection->id]);
        $this->assertDatabaseMissing('prospect_sources', ['id' => $source->id]);
        $this->assertDatabaseMissing('prospect_source_versions', ['id' => $version->id]);
        $this->assertDatabaseMissing('source_runs', ['id' => $run->id]);
        $this->assertDatabaseMissing('source_observations', ['id' => $observation->id]);
        $this->assertDatabaseMissing('person_source_memberships', ['id' => $membership->id]);
        $this->assertDatabaseMissing('external_identities', ['id' => $identity->id]);
        $this->assertDatabaseMissing('canonical_field_provenance', ['id' => $provenance->id]);
        $this->assertDatabaseMissing('people', ['id' => $person->id]);
    }

    public function test_account_deletion_does_not_remove_a_shared_workspace(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->withoutWorkspace()->create();
        $workspace = Workspace::factory()->create(['type' => 'team']);
        WorkspaceMembership::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceMembership::ROLE_OWNER,
        ]);
        WorkspaceMembership::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $otherUser->id,
            'role' => 'member',
        ]);

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('home'));
        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id, 'type' => 'team']);
        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $otherUser->id,
        ]);
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
