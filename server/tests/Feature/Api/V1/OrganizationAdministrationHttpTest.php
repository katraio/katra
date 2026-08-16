<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Enums\ChannelVisibility;
use App\Enums\OrganizationRole;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\ConversationMembership;
use App\Models\Organization;
use App\Models\OrganizationAdministrationEvent;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class OrganizationAdministrationHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bouncer::scope()->remove();
        $this->seed(AuthorizationSeeder::class);
    }

    protected function tearDown(): void
    {
        Bouncer::scope()->remove();

        parent::tearDown();
    }

    public function test_only_global_administrators_can_use_organization_administration(): void
    {
        $organization = Organization::factory()->create();
        $ordinary = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $organizationAdministrator = $this->memberWithRole(
            $organization,
            OrganizationRole::Administrator,
        );
        $clientAdministrator = $this->memberWithRole(
            $organization,
            OrganizationRole::ClientAdministrator,
            client: true,
        );

        $this->getJson('/api/v1/organization-administration')->assertUnauthorized();

        foreach ([$ordinary, $organizationAdministrator, $clientAdministrator] as $user) {
            $this->actingAs($user)
                ->getJson('/api/v1/organization-administration')
                ->assertForbidden();
            $this->actingAs($user)
                ->postJson('/api/v1/organization-administration', ['name' => 'Unauthorized Client'])
                ->assertForbidden();
            $this->actingAs($user)
                ->patchJson(
                    "/api/v1/organization-administration/{$organization->public_id}",
                    ['name' => 'Unauthorized Rename'],
                )
                ->assertForbidden();
        }
    }

    public function test_global_administrator_lists_every_organization_with_active_member_counts(): void
    {
        $administrator = $this->globalAdministrator();
        $operating = Organization::factory()->operating()->create(['name' => 'DevOption']);
        $client = Organization::factory()->create(['name' => 'Katra QA']);
        OrganizationMembership::factory()->create(['organization_id' => $client]);
        OrganizationMembership::factory()->suspended()->create(['organization_id' => $client]);

        $response = $this->actingAs($administrator)
            ->getJson('/api/v1/organization-administration')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'DevOption')
            ->assertJsonPath('data.0.kind', 'operating')
            ->assertJsonPath('data.0.member_count', 0)
            ->assertJsonPath('data.0.actions.update', true)
            ->assertJsonPath('data.1.name', 'Katra QA')
            ->assertJsonPath('data.1.kind', 'client')
            ->assertJsonPath('data.1.member_count', 1);

        $this->assertSame($operating->public_id, $response->json('data.0.id'));
        $this->assertSame($client->public_id, $response->json('data.1.id'));
        $this->assertArrayNotHasKey('organization_id', $response->json('data.1'));
    }

    public function test_create_atomically_establishes_client_organization_team_channel_and_event(): void
    {
        $administrator = $this->globalAdministrator();

        $response = $this->actingAs($administrator)
            ->postJson('/api/v1/organization-administration', [
                'name' => '  Acme   Field Services  ',
            ])
            ->assertCreated()
            ->assertHeader('X-Katra-Request-Id')
            ->assertJsonPath('data.name', 'Acme Field Services')
            ->assertJsonPath('data.slug', 'acme-field-services')
            ->assertJsonPath('data.kind', 'client')
            ->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.actions.update', true);

        $organization = Organization::query()->where('slug', 'acme-field-services')->sole();
        $team = Channel::query()->where('organization_id', $organization->getKey())->sole();

        $this->assertSame(ChannelVisibility::ClientTeam, $team->visibility);
        $this->assertSame('Team', $team->name);
        $this->assertSame('team', $team->slug);
        $this->assertSame(0, ConversationMembership::query()
            ->where('conversation_id', $team->conversation_id)
            ->count());
        $this->assertDatabaseHas('organization_administration_events', [
            'organization_id' => $organization->getKey(),
            'actor_user_id' => $administrator->getKey(),
            'kind' => 'created',
            'previous_name' => null,
            'current_name' => 'Acme Field Services',
            'request_id' => $response->headers->get('X-Katra-Request-Id'),
        ]);
    }

    public function test_create_rejects_duplicate_slug_and_unsupported_identity_fields_without_partial_records(): void
    {
        $administrator = $this->globalAdministrator();
        Organization::factory()->create(['name' => 'Existing Client', 'slug' => 'existing-client']);
        $organizationCount = Organization::query()->count();

        $this->actingAs($administrator)
            ->postJson('/api/v1/organization-administration', [
                'name' => 'Existing Client',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->actingAs($administrator)
            ->postJson('/api/v1/organization-administration', [
                'name' => 'Another Client',
                'slug' => 'chosen-by-client',
                'kind' => 'operating',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $this->assertSame($organizationCount, Organization::query()->count());
        $this->assertSame(0, Channel::query()->count());
        $this->assertSame(0, OrganizationAdministrationEvent::query()->count());
    }

    public function test_rename_changes_only_name_and_records_previous_and_current_values(): void
    {
        $administrator = $this->globalAdministrator();
        $organization = Organization::factory()->create([
            'name' => 'Acme Field Services',
            'slug' => 'acme-field-services',
        ]);

        $response = $this->actingAs($administrator)
            ->patchJson(
                "/api/v1/organization-administration/{$organization->public_id}",
                ['name' => 'Acme Service Group'],
            )
            ->assertOk()
            ->assertHeader('X-Katra-Request-Id')
            ->assertJsonPath('data.name', 'Acme Service Group')
            ->assertJsonPath('data.slug', 'acme-field-services')
            ->assertJsonPath('data.kind', 'client');

        $organization->refresh();
        $this->assertSame('Acme Service Group', $organization->name);
        $this->assertSame('acme-field-services', $organization->slug);
        $this->assertSame('client', $organization->kind->value);
        $this->assertDatabaseHas('organization_administration_events', [
            'organization_id' => $organization->getKey(),
            'actor_user_id' => $administrator->getKey(),
            'kind' => 'renamed',
            'previous_name' => 'Acme Field Services',
            'current_name' => 'Acme Service Group',
            'request_id' => $response->headers->get('X-Katra-Request-Id'),
        ]);
    }

    public function test_organization_administration_events_are_immutable(): void
    {
        $administrator = $this->globalAdministrator();
        $this->actingAs($administrator)
            ->postJson('/api/v1/organization-administration', ['name' => 'Immutable Client'])
            ->assertCreated();
        $event = OrganizationAdministrationEvent::query()->sole();

        $this->expectException(QueryException::class);

        $event->forceFill(['current_name' => 'Rewritten Client'])->save();
    }

    private function globalAdministrator(): User
    {
        $user = User::factory()->create();
        $user->assign(SystemRole::GlobalAdministrator->value);

        return $user;
    }

    private function memberWithRole(
        Organization $organization,
        OrganizationRole $role,
        bool $client = false,
    ): User {
        $user = User::factory()->create();
        OrganizationMembership::factory()
            ->when($client, fn ($factory) => $factory->client())
            ->create([
                'organization_id' => $organization,
                'user_id' => $user,
                'kind' => $role->membershipKind(),
            ]);
        app(OrganizationAuthorization::class)->assign($user, $organization, $role);

        return $user;
    }
}
