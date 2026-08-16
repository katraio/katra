<?php

namespace Tests\Feature;

use App\Auth\OrganizationAuthorization;
use App\Conversations\DirectMessageAccess;
use App\Conversations\DirectMessageService;
use App\Enums\DirectMessageState;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\SystemRole;
use App\Models\ConversationMembership;
use App\Models\DirectMessageTransition;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class DirectMessageAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private DirectMessageService $directMessages;

    private DirectMessageAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        Bouncer::scope()->remove();
        $this->seed(AuthorizationSeeder::class);
        $this->directMessages = app(DirectMessageService::class);
        $this->access = app(DirectMessageAccess::class);
    }

    protected function tearDown(): void
    {
        Bouncer::scope()->remove();

        parent::tearDown();
    }

    public function test_only_participants_can_discover_a_direct_message_even_when_an_outsider_is_an_administrator(): void
    {
        $operating = Organization::factory()->operating()->create();
        $initiator = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $organizationAdministrator = $this->memberWithRole($operating, OrganizationRole::Administrator);
        $globalAdministrator = User::factory()->create();
        $globalAdministrator->assign(SystemRole::GlobalAdministrator->value);
        Bouncer::refresh($globalAdministrator);
        $directMessage = $this->directMessages->create($operating, $initiator, [$participant]);

        ConversationMembership::factory()->create([
            'conversation_id' => $directMessage->conversation_id,
            'user_id' => $organizationAdministrator,
        ]);

        $this->assertTrue($this->access->canRead($initiator, $directMessage));
        $this->assertTrue($this->access->canRead($participant, $directMessage));
        $this->assertFalse($this->access->canRead($organizationAdministrator, $directMessage));
        $this->assertFalse($this->access->canRead($globalAdministrator, $directMessage));
        $this->assertSame([], $this->access->visibleTo($globalAdministrator)->pluck('id')->all());
    }

    public function test_internal_group_direct_message_is_canonical_for_the_exact_participant_set(): void
    {
        $operating = Organization::factory()->operating()->create();
        $initiator = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $second = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $third = $this->memberWithRole($operating, OrganizationRole::InternalMember);

        $created = $this->directMessages->create($operating, $initiator, [$second, $third]);
        $reused = $this->directMessages->create($operating, $third, [$second, $initiator]);

        $this->assertTrue($created->is($reused));
        $this->assertCount(3, $created->participants);
        $this->assertDatabaseCount('direct_messages', 1);
        $this->assertDatabaseCount('direct_message_transitions', 1);
    }

    public function test_internal_member_can_create_a_group_with_multiple_clients_from_one_client_organization(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $initiator = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $internalParticipant = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($initiator, $clientOrganization, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($internalParticipant, $clientOrganization, OrganizationRole::InternalMember);
        $firstClient = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $secondClient = $this->memberWithRole($clientOrganization, OrganizationRole::ClientAdministrator);

        $directMessage = $this->directMessages->create(
            $clientOrganization,
            $initiator,
            [$internalParticipant, $firstClient, $secondClient],
        );

        $this->assertCount(4, $directMessage->participants);
        $this->assertSame($initiator->getKey(), $directMessage->internal_owner_user_id);
        $this->assertTrue($this->access->canRead($firstClient, $directMessage));
        $this->assertTrue($this->access->canRead($secondClient, $directMessage));
    }

    public function test_client_direct_message_rejects_a_client_from_another_organization(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $otherClientOrganization = Organization::factory()->create();
        $initiator = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($initiator, $clientOrganization, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $otherClient = $this->memberWithRole($otherClientOrganization, OrganizationRole::ClientMember);

        try {
            $this->directMessages->create($clientOrganization, $initiator, [$client, $otherClient]);
            $this->fail('A Direct Message crossed client Organization boundaries.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('participant_ids', $exception->errors());
        }

        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_client_cannot_create_a_direct_message(): void
    {
        Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $otherClient = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);

        $this->expectException(AuthorizationException::class);

        $this->directMessages->create($clientOrganization, $client, [$otherClient]);
    }

    public function test_suspended_client_membership_revokes_direct_message_access(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $initiator = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($initiator, $clientOrganization, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $directMessage = $this->directMessages->create($clientOrganization, $initiator, [$client]);

        $this->assertTrue($this->access->canRead($client, $directMessage));

        $client->organizationMemberships()
            ->where('organization_id', $clientOrganization->getKey())
            ->update([
                'status' => MembershipStatus::Suspended,
                'suspended_at' => now(),
            ]);

        $this->assertFalse($this->access->canRead($client, $directMessage));
    }

    public function test_client_completion_continuation_and_reopen_are_participant_only_and_audited(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $internal = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $otherInternal = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($internal, $clientOrganization, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($otherInternal, $clientOrganization, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $outsider = User::factory()->create();
        $outsider->assign(SystemRole::GlobalAdministrator->value);
        Bouncer::refresh($outsider);
        $directMessage = $this->directMessages->create(
            $clientOrganization,
            $internal,
            [$otherInternal, $client],
        );

        $completed = $this->directMessages->complete($directMessage, $otherInternal);
        $requested = $this->directMessages->requestContinuation($completed, $client);
        $replayed = $this->directMessages->requestContinuation($requested, $client);

        $this->assertSame(DirectMessageState::ContinuationRequested, $requested->state);
        $this->assertTrue($requested->is($replayed));
        $this->assertDatabaseCount('direct_message_transitions', 3);

        try {
            $this->directMessages->reopen($requested, $outsider);
            $this->fail('A nonparticipant global administrator reopened a private Direct Message.');
        } catch (AuthorizationException) {
            $this->assertSame(DirectMessageState::ContinuationRequested, $requested->fresh()->state);
        }

        $reopened = $this->directMessages->reopen($requested, $internal);

        $this->assertSame(DirectMessageState::Open, $reopened->state);
        $this->assertNull($reopened->completed_at);
        $this->assertDatabaseCount('direct_message_transitions', 4);
    }

    public function test_transition_history_is_immutable(): void
    {
        $operating = Organization::factory()->operating()->create();
        $initiator = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $this->directMessages->create($operating, $initiator, [$participant]);
        $transition = DirectMessageTransition::query()->sole();

        $this->expectException(QueryException::class);

        $transition->forceFill(['to_state' => DirectMessageState::Completed])->save();
    }

    public function test_database_rejects_a_nonparticipant_transition_actor(): void
    {
        $operating = Organization::factory()->operating()->create();
        $initiator = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($operating, OrganizationRole::Administrator);
        $directMessage = $this->directMessages->create($operating, $initiator, [$participant]);

        $this->expectException(QueryException::class);

        $directMessage->transitions()->create([
            'from_state' => DirectMessageState::Open,
            'to_state' => DirectMessageState::Completed,
            'actor_user_id' => $outsider->getKey(),
            'created_at' => now(),
        ]);
    }

    private function memberWithRole(Organization $organization, OrganizationRole $role): User
    {
        $user = User::factory()->create();
        $this->assignRoleInOrganization($user, $organization, $role);

        return $user;
    }

    private function assignRoleInOrganization(
        User $user,
        Organization $organization,
        OrganizationRole $role,
    ): void {
        OrganizationMembership::factory()->create([
            'organization_id' => $organization,
            'user_id' => $user,
            'kind' => $role->membershipKind(),
        ]);

        app(OrganizationAuthorization::class)->assign($user, $organization, $role);
    }
}
