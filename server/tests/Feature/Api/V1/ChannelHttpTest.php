<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Conversations\ChannelService;
use App\Enums\ChannelMembershipRole;
use App\Enums\ChannelVisibility;
use App\Enums\OrganizationRole;
use App\Models\ConversationMembership;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class ChannelHttpTest extends TestCase
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

    public function test_guest_cannot_use_channel_resources(): void
    {
        $this->getJson('/api/v1/channels')->assertUnauthorized();
    }

    public function test_internal_member_can_create_read_join_and_leave_public_channels(): void
    {
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $member = $this->memberWithRole($operating, OrganizationRole::InternalMember);

        $created = $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$operating->public_id}/channels", [
                'name' => 'Product Updates',
                'visibility' => ChannelVisibility::Public->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.organization_id', $operating->public_id)
            ->assertJsonPath('data.slug', 'product-updates')
            ->assertJsonPath('data.visibility', ChannelVisibility::Public->value)
            ->assertJsonPath('data.membership.role', ChannelMembershipRole::Owner->value);

        $channelId = $created->json('data.id');

        $this->actingAs($member)
            ->getJson('/api/v1/channels')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $channelId)
            ->assertJsonPath('data.0.membership', null);

        $this->actingAs($member)
            ->postJson("/api/v1/channels/{$channelId}/join")
            ->assertOk()
            ->assertJsonPath('data.membership.role', ChannelMembershipRole::Member->value);

        $this->actingAs($member)
            ->deleteJson("/api/v1/channels/{$channelId}/membership")
            ->assertNoContent();

        $this->assertNotNull(ConversationMembership::query()
            ->where('user_id', $member->getKey())
            ->sole()
            ->left_at);
    }

    public function test_private_channel_is_not_disclosed_until_the_owner_invites_the_internal_member(): void
    {
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $member = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'Leadership',
            ChannelVisibility::Private,
        );

        $this->actingAs($member)
            ->getJson("/api/v1/channels/{$channel->conversation->public_id}")
            ->assertNotFound();

        app(ChannelService::class)->inviteToPrivate($channel, $owner, $member);

        $this->actingAs($member)
            ->getJson("/api/v1/channels/{$channel->conversation->public_id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Leadership');
    }

    public function test_visible_channel_can_be_favorited_and_unfavorited_without_changing_access(): void
    {
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $reader = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'General',
            ChannelVisibility::Public,
        );
        $channelId = $channel->conversation->public_id;

        $this->actingAs($reader)
            ->putJson("/api/v1/channels/{$channelId}/favorite")
            ->assertOk()
            ->assertJsonPath('data.is_favorite', true)
            ->assertJsonPath('data.membership', null);

        $this->assertDatabaseHas('conversation_favorites', [
            'conversation_id' => $channel->conversation_id,
            'user_id' => $reader->getKey(),
        ]);

        $this->actingAs($reader)
            ->getJson('/api/v1/channels')
            ->assertOk()
            ->assertJsonPath('data.0.is_favorite', true)
            ->assertJsonPath('data.0.membership', null);

        $this->actingAs($reader)
            ->deleteJson("/api/v1/channels/{$channelId}/favorite")
            ->assertOk()
            ->assertJsonPath('data.is_favorite', false);

        $this->assertDatabaseMissing('conversation_favorites', [
            'conversation_id' => $channel->conversation_id,
            'user_id' => $reader->getKey(),
        ]);
    }

    public function test_favorite_never_reveals_an_unreadable_private_channel(): void
    {
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'Leadership',
            ChannelVisibility::Private,
        );

        $this->actingAs($outsider)
            ->putJson("/api/v1/channels/{$channel->conversation->public_id}/favorite")
            ->assertNotFound();

        $this->assertDatabaseMissing('conversation_favorites', [
            'conversation_id' => $channel->conversation_id,
            'user_id' => $outsider->getKey(),
        ]);
    }

    public function test_client_cannot_discover_internal_channels_even_with_a_malformed_membership(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'Announcements',
            ChannelVisibility::Public,
        );
        ConversationMembership::factory()->create([
            'conversation_id' => $channel->conversation_id,
            'user_id' => $client,
            'channel_role' => ChannelMembershipRole::Member,
        ]);

        $this->actingAs($client)
            ->getJson('/api/v1/channels')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($client)
            ->getJson("/api/v1/channels/{$channel->conversation->public_id}")
            ->assertNotFound();
        $this->actingAs($client)
            ->postJson("/api/v1/channels/{$channel->conversation->public_id}/join")
            ->assertNotFound();
    }

    public function test_owner_can_rename_but_only_an_administrator_can_archive(): void
    {
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $administrator = $this->memberWithRole($operating, OrganizationRole::Administrator);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'Ideas',
            ChannelVisibility::Private,
        );
        $channelId = $channel->conversation->public_id;

        $this->actingAs($owner)
            ->patchJson("/api/v1/channels/{$channelId}", ['name' => 'Product Ideas'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'product-ideas');

        $this->actingAs($owner)
            ->postJson("/api/v1/channels/{$channelId}/archive")
            ->assertForbidden();

        $this->actingAs($administrator)
            ->postJson("/api/v1/channels/{$channelId}/archive")
            ->assertNoContent();
    }

    public function test_client_organization_rejects_public_or_private_channel_creation(): void
    {
        $clientOrganization = Organization::factory()->create();
        $administrator = $this->memberWithRole($clientOrganization, OrganizationRole::Administrator);

        $this->actingAs($administrator)
            ->postJson("/api/v1/organizations/{$clientOrganization->public_id}/channels", [
                'name' => 'Not Public',
                'visibility' => ChannelVisibility::Public->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization');
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
