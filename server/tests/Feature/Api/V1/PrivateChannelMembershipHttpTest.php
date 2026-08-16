<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Conversations\ChannelService;
use App\Enums\ChannelMembershipRole;
use App\Enums\ChannelVisibility;
use App\Enums\OrganizationRole;
use App\Events\ConversationAccessChanged;
use App\Models\ConversationMembership;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class PrivateChannelMembershipHttpTest extends TestCase
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

    public function test_owner_can_search_internal_candidates_invite_and_promote_without_exposing_clients_or_email(): void
    {
        Event::fake([ConversationAccessChanged::class]);
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $candidate = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'Leadership',
            ChannelVisibility::Private,
        );
        $channelId = $channel->conversation->public_id;

        $this->actingAs($owner)
            ->getJson("/api/v1/channels/{$channelId}/member-candidates")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($owner)
            ->getJson("/api/v1/channels/{$channelId}/member-candidates?query=".urlencode($candidate->first_name))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $candidate->public_id)
            ->assertJsonMissingPath('data.0.email');

        $this->actingAs($owner)
            ->postJson("/api/v1/channels/{$channelId}/members", ['user_id' => $candidate->public_id])
            ->assertCreated()
            ->assertJsonPath('data.role', ChannelMembershipRole::Member->value)
            ->assertJsonPath('data.name', $candidate->name);

        Event::assertDispatched(
            ConversationAccessChanged::class,
            fn (ConversationAccessChanged $event): bool => $event->userId === $candidate->public_id
                && $event->conversationId === $channelId
                && $event->operation === 'granted',
        );

        $this->actingAs($owner)
            ->putJson("/api/v1/channels/{$channelId}/members/{$candidate->public_id}/owner")
            ->assertOk()
            ->assertJsonPath('data.role', ChannelMembershipRole::Owner->value);

        $this->actingAs($candidate)
            ->getJson("/api/v1/channels/{$channelId}")
            ->assertOk()
            ->assertJsonPath('data.permissions.can_manage_members', true);

        $this->actingAs($owner)
            ->postJson("/api/v1/channels/{$channelId}/members", ['user_id' => $candidate->public_id])
            ->assertCreated()
            ->assertJsonPath('data.role', ChannelMembershipRole::Owner->value);
        Event::assertDispatchedTimes(ConversationAccessChanged::class, 1);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/channels/{$channelId}/members/{$candidate->public_id}/owner")
            ->assertOk()
            ->assertJsonPath('data.role', ChannelMembershipRole::Member->value);
    }

    public function test_private_member_can_view_roster_but_cannot_search_or_mutate_membership(): void
    {
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $member = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $candidate = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'Leadership',
            ChannelVisibility::Private,
        );
        app(ChannelService::class)->inviteToPrivate($channel, $owner, $member);
        $channelId = $channel->conversation->public_id;

        $this->actingAs($member)
            ->getJson("/api/v1/channels/{$channelId}/members")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.role', ChannelMembershipRole::Owner->value);

        $this->actingAs($member)
            ->getJson("/api/v1/channels/{$channelId}/member-candidates")
            ->assertForbidden();
        $this->actingAs($member)
            ->postJson("/api/v1/channels/{$channelId}/members", ['user_id' => $candidate->public_id])
            ->assertForbidden();
        $this->actingAs($member)
            ->deleteJson("/api/v1/channels/{$channelId}/members/{$owner->public_id}")
            ->assertForbidden();
    }

    public function test_revocation_removes_private_visibility_and_reactivation_restores_member_role(): void
    {
        Event::fake([ConversationAccessChanged::class]);
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $member = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'Leadership',
            ChannelVisibility::Private,
        );
        app(ChannelService::class)->inviteToPrivate($channel, $owner, $member);
        app(ChannelService::class)->promoteOwner($channel, $owner, $member);
        $channelId = $channel->conversation->public_id;

        $this->actingAs($owner)
            ->deleteJson("/api/v1/channels/{$channelId}/members/{$member->public_id}")
            ->assertNoContent();

        $this->actingAs($member)
            ->getJson("/api/v1/channels/{$channelId}")
            ->assertNotFound();

        Event::assertDispatched(
            ConversationAccessChanged::class,
            fn (ConversationAccessChanged $event): bool => $event->userId === $member->public_id
                && $event->operation === 'revoked',
        );

        $this->actingAs($owner)
            ->postJson("/api/v1/channels/{$channelId}/members", ['user_id' => $member->public_id])
            ->assertCreated()
            ->assertJsonPath('data.role', ChannelMembershipRole::Member->value);
    }

    public function test_final_owner_cannot_be_removed_and_client_cannot_discover_membership_resources(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'Leadership',
            ChannelVisibility::Private,
        );
        $channelId = $channel->conversation->public_id;
        ConversationMembership::factory()->create([
            'conversation_id' => $channel->conversation_id,
            'user_id' => $client,
            'channel_role' => ChannelMembershipRole::Member,
        ]);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/channels/{$channelId}/members/{$owner->public_id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('membership');

        $this->actingAs($owner)
            ->deleteJson("/api/v1/channels/{$channelId}/members/{$owner->public_id}/owner")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('membership');

        $this->actingAs($client)
            ->getJson("/api/v1/channels/{$channelId}/members")
            ->assertNotFound();
        $this->actingAs($client)
            ->getJson("/api/v1/channels/{$channelId}/member-candidates")
            ->assertNotFound();
    }

    public function test_owner_can_demote_self_or_leave_once_another_owner_exists(): void
    {
        Event::fake([ConversationAccessChanged::class]);
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $nextOwner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'Leadership',
            ChannelVisibility::Private,
        );
        app(ChannelService::class)->inviteToPrivate($channel, $owner, $nextOwner);
        app(ChannelService::class)->promoteOwner($channel, $owner, $nextOwner);
        $channelId = $channel->conversation->public_id;

        $this->actingAs($owner)
            ->deleteJson("/api/v1/channels/{$channelId}/members/{$owner->public_id}/owner")
            ->assertOk()
            ->assertJsonPath('data.role', ChannelMembershipRole::Member->value);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/channels/{$channelId}/membership")
            ->assertNoContent();

        $this->actingAs($owner)
            ->getJson("/api/v1/channels/{$channelId}")
            ->assertNotFound();

        Event::assertDispatched(
            ConversationAccessChanged::class,
            fn (ConversationAccessChanged $event): bool => $event->userId === $owner->public_id
                && $event->operation === 'revoked',
        );
    }

    private function memberWithRole(Organization $organization, OrganizationRole $role): User
    {
        $user = User::factory()->create();
        OrganizationMembership::factory()->create([
            'organization_id' => $organization,
            'user_id' => $user,
            'kind' => $role->membershipKind(),
        ]);
        app(OrganizationAuthorization::class)->assign($user, $organization, $role);

        return $user;
    }
}
