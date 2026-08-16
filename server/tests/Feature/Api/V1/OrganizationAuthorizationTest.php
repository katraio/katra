<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationScope;
use App\Enums\SystemRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class OrganizationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bouncer::scope()->remove();
    }

    protected function tearDown(): void
    {
        Bouncer::scope()->remove();

        parent::tearDown();
    }

    public function test_guest_cannot_read_organizations(): void
    {
        $organization = Organization::factory()->create();

        $this->getJson('/api/v1/organizations')->assertUnauthorized();
        $this->getJson("/api/v1/organizations/{$organization->public_id}")->assertUnauthorized();
    }

    public function test_global_administrator_can_read_every_organization_without_memberships(): void
    {
        $user = User::factory()->create();
        $operatingOrganization = Organization::factory()->operating()->create([
            'name' => 'DevOption',
            'slug' => 'devoption',
        ]);
        $clientOrganization = Organization::factory()->create([
            'name' => 'Northstar Goods',
            'slug' => 'northstar-goods',
        ]);

        $user->assign(SystemRole::GlobalAdministrator->value);
        Bouncer::refresh($user);

        $this->actingAs($user)
            ->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $operatingOrganization->public_id)
            ->assertJsonPath('data.1.id', $clientOrganization->public_id)
            ->assertJsonMissing(['id' => $clientOrganization->getKey()]);

        $this->actingAs($user)
            ->getJson("/api/v1/organizations/{$clientOrganization->public_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $clientOrganization->public_id)
            ->assertJsonPath('data.kind', 'client');

        $this->actingAs($user)
            ->getJson('/api/v1/auth/user')
            ->assertOk()
            ->assertJsonPath('data.is_global_administrator', true);
    }

    public function test_internal_member_reads_only_actively_assigned_organizations(): void
    {
        $user = User::factory()->create();
        $assignedOrganization = Organization::factory()->create([
            'name' => 'Assigned Client',
            'slug' => 'assigned-client',
        ]);
        $otherOrganization = Organization::factory()->create([
            'name' => 'Other Client',
            'slug' => 'other-client',
        ]);

        OrganizationMembership::factory()->create([
            'organization_id' => $assignedOrganization,
            'user_id' => $user,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignedOrganization->public_id)
            ->assertJsonMissing(['id' => $otherOrganization->public_id]);

        $this->actingAs($user)
            ->getJson("/api/v1/organizations/{$assignedOrganization->public_id}")
            ->assertOk();

        $this->actingAs($user)
            ->getJson("/api/v1/organizations/{$otherOrganization->public_id}")
            ->assertNotFound();
    }

    public function test_client_and_suspended_memberships_do_not_expose_organization_resources(): void
    {
        $clientUser = User::factory()->create();
        $suspendedInternalUser = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->client()->create([
            'organization_id' => $organization,
            'user_id' => $clientUser,
        ]);
        OrganizationMembership::factory()->suspended()->create([
            'organization_id' => $organization,
            'user_id' => $suspendedInternalUser,
        ]);

        $this->actingAs($clientUser)
            ->getJson('/api/v1/organizations')
            ->assertForbidden();
        $this->actingAs($clientUser)
            ->getJson("/api/v1/organizations/{$organization->public_id}")
            ->assertNotFound();

        $this->actingAs($suspendedInternalUser)
            ->getJson('/api/v1/organizations')
            ->assertForbidden();
        $this->actingAs($suspendedInternalUser)
            ->getJson("/api/v1/organizations/{$organization->public_id}")
            ->assertNotFound();
    }

    public function test_organization_scope_restores_the_previous_scope_after_success_and_failure(): void
    {
        $organization = Organization::factory()->create();
        $scope = app(OrganizationScope::class);

        $result = $scope->run($organization->getKey(), function () use ($organization): string {
            $this->assertSame($organization->getKey(), Bouncer::scope()->get());

            return 'complete';
        });

        $this->assertSame('complete', $result);
        $this->assertNull(Bouncer::scope()->get());

        try {
            $scope->run($organization->getKey(), function () use ($organization): never {
                $this->assertSame($organization->getKey(), Bouncer::scope()->get());

                throw new RuntimeException('expected test failure');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('expected test failure', $exception->getMessage());
        }

        $this->assertNull(Bouncer::scope()->get());
    }

    public function test_database_allows_only_one_operating_business(): void
    {
        Organization::factory()->operating()->create();

        $this->expectException(QueryException::class);

        Organization::factory()->operating()->create();
    }
}
