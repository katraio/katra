<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Enums\OrganizationRole;
use App\Enums\SystemRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationInvitationEvent;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Organizations\OrganizationInvitationService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class MemberAdministrationHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bouncer::scope()->remove();
        $this->seed(AuthorizationSeeder::class);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Bouncer::scope()->remove();

        parent::tearDown();
    }

    public function test_global_administrator_discovers_scopes_members_and_allowed_roles(): void
    {
        $administrator = User::factory()->create();
        $administrator->assign(SystemRole::GlobalAdministrator->value);
        $operating = Organization::factory()->create(['name' => 'DevOption', 'kind' => 'operating']);
        $client = Organization::factory()->create(['name' => 'Northstar Goods', 'kind' => 'client']);
        $member = User::factory()->create([
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@example.com',
        ]);
        OrganizationMembership::factory()->create([
            'organization_id' => $operating,
            'user_id' => $member,
        ]);
        app(OrganizationAuthorization::class)->assign($member, $operating, OrganizationRole::InternalMember);

        $scopes = $this->actingAs($administrator)
            ->getJson('/api/v1/member-administration')
            ->assertOk()
            ->json('data');

        $this->assertSame(['DevOption', 'Northstar Goods'], array_column(array_column($scopes, 'organization'), 'name'));
        $this->assertSame(
            [OrganizationRole::Administrator->value, OrganizationRole::InternalMember->value],
            array_column($scopes[0]['allowed_invitation_roles'], 'value'),
        );
        $this->assertCount(4, $scopes[1]['allowed_invitation_roles']);

        $this->actingAs($administrator)
            ->getJson("/api/v1/member-administration/{$operating->public_id}/members")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Grace Hopper')
            ->assertJsonPath('data.0.email', 'grace@example.com')
            ->assertJsonPath('data.0.role', OrganizationRole::InternalMember->value)
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($administrator)
            ->getJson("/api/v1/member-administration/{$client->public_id}/members")
            ->assertOk();
    }

    public function test_ordinary_internal_member_cannot_discover_or_substitute_an_administration_scope(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $other = Organization::factory()->create();

        $this->actingAs($member)
            ->getJson('/api/v1/member-administration')
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->actingAs($member)
            ->getJson("/api/v1/member-administration/{$organization->public_id}/members")
            ->assertNotFound();

        $this->actingAs($member)
            ->getJson("/api/v1/member-administration/{$other->public_id}/invitations")
            ->assertNotFound();
    }

    public function test_client_administrator_receives_only_bounded_client_administration(): void
    {
        $clientOrganization = Organization::factory()->create(['kind' => 'client']);
        $otherOrganization = Organization::factory()->create(['kind' => 'client']);
        $clientAdministrator = $this->memberWithRole(
            $clientOrganization,
            OrganizationRole::ClientAdministrator,
            client: true,
        );
        $clientMember = $this->memberWithRole(
            $clientOrganization,
            OrganizationRole::ClientMember,
            client: true,
        );
        $internalMember = $this->memberWithRole(
            $clientOrganization,
            OrganizationRole::InternalMember,
        );

        $scopeResponse = $this->actingAs($clientAdministrator)
            ->getJson('/api/v1/member-administration')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.organization.id', $clientOrganization->public_id)
            ->assertJsonPath('data.0.allowed_invitation_roles.0.value', OrganizationRole::ClientMember->value);
        $this->assertCount(1, $scopeResponse->json('data.0.allowed_invitation_roles'));

        $memberResponse = $this->actingAs($clientAdministrator)
            ->getJson("/api/v1/member-administration/{$clientOrganization->public_id}/members")
            ->assertOk();
        $memberIds = array_column($memberResponse->json('data'), 'id');
        $this->assertContains($clientAdministrator->public_id, $memberIds);
        $this->assertContains($clientMember->public_id, $memberIds);
        $this->assertNotContains($internalMember->public_id, $memberIds);

        $this->actingAs($clientAdministrator)
            ->getJson("/api/v1/member-administration/{$otherOrganization->public_id}/members")
            ->assertNotFound();
    }

    public function test_invitation_history_is_token_free_and_reissue_rotates_the_acceptance_secret(): void
    {
        $administrator = User::factory()->create();
        $administrator->assign(SystemRole::GlobalAdministrator->value);
        $organization = Organization::factory()->create();

        $created = $this->actingAs($administrator)->postJson(
            "/api/v1/organizations/{$organization->public_id}/invitations",
            [
                'email' => 'member@example.com',
                'role' => OrganizationRole::InternalMember->value,
            ],
        )->assertCreated()
            ->assertJsonPath('data.delivery_status', 'copy-link-only');

        $oldUrl = $created->json('data.acceptance_url');
        parse_str((string) parse_url($oldUrl, PHP_URL_FRAGMENT), $fragment);
        $oldToken = $fragment['token'] ?? '';
        $invitation = OrganizationInvitation::query()->sole();

        $this->actingAs($administrator)
            ->getJson("/api/v1/member-administration/{$organization->public_id}/invitations")
            ->assertOk()
            ->assertJsonPath('data.0.id', $invitation->public_id)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.delivery_status', 'copy-link-only')
            ->assertJsonMissing(['acceptance_url', 'token', 'token_hash'])
            ->assertJsonPath('meta.total', 1);

        $reissued = $this->actingAs($administrator)->postJson(
            "/api/v1/organizations/{$organization->public_id}/invitations/{$invitation->public_id}/reissue",
        )->assertCreated();

        $this->assertNotSame($oldUrl, $reissued->json('data.acceptance_url'));
        $this->postWithCsrf('/auth/invitations/inspect', ['token' => $oldToken])->assertGone();
        $this->assertDatabaseHas('organization_invitation_events', [
            'organization_invitation_id' => $invitation->getKey(),
            'kind' => 'superseded',
            'actor_user_id' => $administrator->getKey(),
        ]);
        $this->assertDatabaseHas('organization_invitation_events', [
            'organization_invitation_id' => OrganizationInvitation::query()->latest('id')->value('id'),
            'kind' => 'reissued',
            'actor_user_id' => $administrator->getKey(),
        ]);
    }

    public function test_client_administrator_sees_and_controls_only_their_own_invitations(): void
    {
        $organization = Organization::factory()->create(['kind' => 'client']);
        $clientAdministrator = $this->memberWithRole(
            $organization,
            OrganizationRole::ClientAdministrator,
            client: true,
        );
        $globalAdministrator = User::factory()->create();
        $globalAdministrator->assign(SystemRole::GlobalAdministrator->value);

        $own = app(OrganizationInvitationService::class)->issue(
            $organization,
            $clientAdministrator,
            'own@example.com',
            OrganizationRole::ClientMember,
        );
        $other = app(OrganizationInvitationService::class)->issue(
            $organization,
            $globalAdministrator,
            'other@example.com',
            OrganizationRole::ClientMember,
        );

        $response = $this->actingAs($clientAdministrator)
            ->getJson("/api/v1/member-administration/{$organization->public_id}/invitations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->invitation->public_id)
            ->assertJsonPath('data.0.actions.reissue', true)
            ->assertJsonPath('data.0.actions.revoke', true);
        $this->assertNotSame($other->invitation->public_id, $response->json('data.0.id'));

        $this->actingAs($clientAdministrator)->postJson(
            "/api/v1/organizations/{$organization->public_id}/invitations/{$other->invitation->public_id}/reissue",
        )->assertForbidden();
    }

    public function test_organization_invitation_events_are_immutable(): void
    {
        $administrator = User::factory()->create();
        $administrator->assign(SystemRole::GlobalAdministrator->value);
        $organization = Organization::factory()->create();
        $issued = app(OrganizationInvitationService::class)->issue(
            $organization,
            $administrator,
            'member@example.com',
            OrganizationRole::InternalMember,
        );
        $event = OrganizationInvitationEvent::query()
            ->where('organization_invitation_id', $issued->invitation->getKey())
            ->where('kind', 'issued')
            ->sole();

        $this->expectException(QueryException::class);

        $event->forceFill(['kind' => 'revoked'])->save();
    }

    /** @param array<string, mixed> $data */
    private function postWithCsrf(string $uri, array $data = []): TestResponse
    {
        $token = 'katra-invitation-csrf-token';

        return $this->withSession(['_token' => $token])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => $token]);
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
