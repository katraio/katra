<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Conversations\DirectMessageService;
use App\Enums\DirectMessageState;
use App\Enums\OrganizationRole;
use App\Enums\SystemRole;
use App\Events\ConversationAccessChanged;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class DirectMessageHttpTest extends TestCase
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

    public function test_guest_cannot_use_direct_message_resources(): void
    {
        $this->getJson('/api/v1/direct-messages')->assertUnauthorized();
    }

    public function test_internal_creator_can_search_only_valid_name_only_candidates_and_notify_new_participants(): void
    {
        Event::fake([ConversationAccessChanged::class]);
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $otherClientOrganization = Organization::factory()->create();
        $initiator = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $internal = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $operatingOnly = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($initiator, $clientOrganization, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($internal, $clientOrganization, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $otherClient = $this->memberWithRole($otherClientOrganization, OrganizationRole::ClientMember);

        $candidates = $this->actingAs($initiator)
            ->getJson("/api/v1/organizations/{$clientOrganization->public_id}/direct-message-candidates")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'id' => $internal->public_id,
                'name' => $internal->name,
                'kind' => 'internal',
            ])
            ->assertJsonFragment([
                'id' => $client->public_id,
                'name' => $client->name,
                'kind' => 'client',
            ])
            ->assertJsonMissing(['id' => $initiator->public_id])
            ->assertJsonMissing(['id' => $operatingOnly->public_id])
            ->assertJsonMissing(['id' => $otherClient->public_id]);

        $this->assertArrayNotHasKey('email', $candidates->json('data.0'));

        $this->actingAs($initiator)
            ->getJson(
                "/api/v1/organizations/{$clientOrganization->public_id}/direct-message-candidates?query="
                .urlencode($client->first_name),
            )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $client->public_id);

        $created = $this->actingAs($initiator)
            ->postJson("/api/v1/organizations/{$clientOrganization->public_id}/direct-messages", [
                'participant_ids' => [$internal->public_id, $client->public_id],
            ])
            ->assertCreated()
            ->assertJsonCount(3, 'data.participants');

        Event::assertDispatchedTimes(ConversationAccessChanged::class, 2);
        Event::assertDispatched(
            ConversationAccessChanged::class,
            fn (ConversationAccessChanged $event): bool => $event->userId === $client->public_id
                && $event->conversationId === $created->json('data.id')
                && $event->operation === 'granted',
        );

        $this->actingAs($client)
            ->getJson("/api/v1/organizations/{$clientOrganization->public_id}/direct-message-candidates")
            ->assertNotFound();
    }

    public function test_internal_member_can_create_a_group_with_multiple_same_organization_clients(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $initiator = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $otherInternal = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($initiator, $clientOrganization, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($otherInternal, $clientOrganization, OrganizationRole::InternalMember);
        $firstClient = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $secondClient = $this->memberWithRole($clientOrganization, OrganizationRole::ClientAdministrator);

        $created = $this->actingAs($initiator)
            ->postJson("/api/v1/organizations/{$clientOrganization->public_id}/direct-messages", [
                'participant_ids' => [
                    $otherInternal->public_id,
                    $firstClient->public_id,
                    $secondClient->public_id,
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.organization_id', $clientOrganization->public_id)
            ->assertJsonPath('data.state', DirectMessageState::Open->value)
            ->assertJsonCount(4, 'data.participants');

        $directMessageId = $created->json('data.id');

        $this->actingAs($firstClient)
            ->getJson('/api/v1/direct-messages')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $directMessageId);

        $this->actingAs($secondClient)
            ->getJson("/api/v1/direct-messages/{$directMessageId}")
            ->assertOk()
            ->assertJsonPath('data.id', $directMessageId);
    }

    public function test_repeating_the_same_participant_set_returns_the_canonical_direct_message(): void
    {
        $operating = Organization::factory()->operating()->create();
        $first = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $second = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $third = $this->memberWithRole($operating, OrganizationRole::InternalMember);

        $firstResponse = $this->actingAs($first)
            ->postJson("/api/v1/organizations/{$operating->public_id}/direct-messages", [
                'participant_ids' => [$second->public_id, $third->public_id],
            ])
            ->assertCreated();

        $secondResponse = $this->actingAs($third)
            ->postJson("/api/v1/organizations/{$operating->public_id}/direct-messages", [
                'participant_ids' => [$first->public_id, $second->public_id],
            ])
            ->assertOk();

        $this->assertSame($firstResponse->json('data.id'), $secondResponse->json('data.id'));
        $this->assertDatabaseCount('direct_messages', 1);
    }

    public function test_participant_can_favorite_and_unfavorite_without_widening_direct_message_access(): void
    {
        $operating = Organization::factory()->operating()->create();
        $first = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $second = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($operating, OrganizationRole::Administrator);
        $directMessage = app(DirectMessageService::class)->create($operating, $first, [$second]);
        $directMessageId = $directMessage->conversation->public_id;

        $this->actingAs($first)
            ->putJson("/api/v1/direct-messages/{$directMessageId}/favorite")
            ->assertOk()
            ->assertJsonPath('data.is_favorite', true);

        $this->assertDatabaseHas('conversation_favorites', [
            'conversation_id' => $directMessage->conversation_id,
            'user_id' => $first->getKey(),
        ]);

        $this->actingAs($second)
            ->getJson('/api/v1/direct-messages')
            ->assertOk()
            ->assertJsonPath('data.0.is_favorite', false);

        $this->actingAs($outsider)
            ->putJson("/api/v1/direct-messages/{$directMessageId}/favorite")
            ->assertNotFound();

        $this->actingAs($first)
            ->deleteJson("/api/v1/direct-messages/{$directMessageId}/favorite")
            ->assertOk()
            ->assertJsonPath('data.is_favorite', false);

        $this->assertDatabaseMissing('conversation_favorites', [
            'conversation_id' => $directMessage->conversation_id,
            'user_id' => $first->getKey(),
        ]);
    }

    public function test_client_cannot_create_a_direct_message_or_add_participants(): void
    {
        Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $otherClient = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);

        $this->actingAs($client)
            ->postJson("/api/v1/organizations/{$clientOrganization->public_id}/direct-messages", [
                'participant_ids' => [$otherClient->public_id],
            ])
            ->assertNotFound();
    }

    public function test_cross_client_organization_participant_is_rejected_atomically(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $otherClientOrganization = Organization::factory()->create();
        $initiator = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($initiator, $clientOrganization, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $otherClient = $this->memberWithRole($otherClientOrganization, OrganizationRole::ClientMember);

        $this->actingAs($initiator)
            ->postJson("/api/v1/organizations/{$clientOrganization->public_id}/direct-messages", [
                'participant_ids' => [$client->public_id, $otherClient->public_id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('participant_ids');

        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_nonparticipant_administrators_receive_no_discovery_or_lifecycle_override(): void
    {
        $operating = Organization::factory()->operating()->create();
        $first = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $second = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $administrator = $this->memberWithRole($operating, OrganizationRole::Administrator);
        $globalAdministrator = User::factory()->create();
        $globalAdministrator->assign(SystemRole::GlobalAdministrator->value);
        Bouncer::refresh($globalAdministrator);
        $directMessage = app(DirectMessageService::class)->create($operating, $first, [$second]);
        $directMessageId = $directMessage->conversation->public_id;

        foreach ([$administrator, $globalAdministrator] as $outsider) {
            $this->actingAs($outsider)
                ->getJson('/api/v1/direct-messages')
                ->assertOk()
                ->assertJsonCount(0, 'data');
            $this->actingAs($outsider)
                ->getJson("/api/v1/direct-messages/{$directMessageId}")
                ->assertNotFound();
            $this->actingAs($outsider)
                ->postJson("/api/v1/direct-messages/{$directMessageId}/reopen")
                ->assertNotFound();
        }
    }

    public function test_client_lifecycle_endpoints_require_the_correct_participant_type(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $internal = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($internal, $clientOrganization, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $directMessage = app(DirectMessageService::class)
            ->create($clientOrganization, $internal, [$client]);
        $directMessageId = $directMessage->conversation->public_id;

        $this->actingAs($client)
            ->postJson("/api/v1/direct-messages/{$directMessageId}/complete")
            ->assertForbidden();

        $this->actingAs($internal)
            ->postJson("/api/v1/direct-messages/{$directMessageId}/complete")
            ->assertOk()
            ->assertJsonPath('data.state', DirectMessageState::Completed->value);

        $this->actingAs($internal)
            ->postJson("/api/v1/direct-messages/{$directMessageId}/continuation-requests")
            ->assertForbidden();

        $this->actingAs($client)
            ->postJson("/api/v1/direct-messages/{$directMessageId}/continuation-requests")
            ->assertOk()
            ->assertJsonPath('data.state', DirectMessageState::ContinuationRequested->value);

        $this->actingAs($client)
            ->postJson("/api/v1/direct-messages/{$directMessageId}/reopen")
            ->assertForbidden();

        $this->actingAs($internal)
            ->postJson("/api/v1/direct-messages/{$directMessageId}/reopen")
            ->assertOk()
            ->assertJsonPath('data.state', DirectMessageState::Open->value);
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
