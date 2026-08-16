<?php

namespace Tests\Feature;

use App\Auth\OrganizationAuthorization;
use App\Conversations\ChannelAccess;
use App\Conversations\ChannelService;
use App\Enums\ChannelMembershipRole;
use App\Enums\ChannelVisibility;
use App\Enums\ConversationType;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationMembership;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private ChannelService $channels;

    private ChannelAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        Bouncer::scope()->remove();
        $this->seed(AuthorizationSeeder::class);
        $this->channels = app(ChannelService::class);
        $this->access = app(ChannelAccess::class);
    }

    protected function tearDown(): void
    {
        Bouncer::scope()->remove();

        parent::tearDown();
    }

    public function test_public_and_private_channels_are_internal_only_even_with_a_malformed_client_membership(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $creator = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $otherInternal = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);

        $public = $this->channels->createInternal($operating, $creator, 'Announcements', ChannelVisibility::Public);
        $private = $this->channels->createInternal($operating, $creator, 'Leadership', ChannelVisibility::Private);

        ConversationMembership::factory()->create([
            'conversation_id' => $public->conversation_id,
            'user_id' => $client,
            'channel_role' => ChannelMembershipRole::Member,
        ]);

        $this->assertTrue($this->access->canRead($otherInternal, $public));
        $this->assertFalse($this->access->canRead($otherInternal, $private));
        $this->assertFalse($this->access->canRead($client, $public));
        $this->assertFalse($this->access->canRead($client, $private));
        $this->assertSame([], $this->access->visibleTo($client)->pluck('id')->all());
    }

    public function test_internal_channel_creation_is_limited_to_the_operating_organization(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $creator = $this->memberWithRole($operating, OrganizationRole::InternalMember);

        $this->channels->createInternal($operating, $creator, 'General', ChannelVisibility::Public);

        $this->expectException(ValidationException::class);

        $this->channels->createInternal(
            $clientOrganization,
            $creator,
            'Leaked Internal Channel',
            ChannelVisibility::Private,
        );
    }

    public function test_malformed_internal_channels_under_a_client_organization_remain_inaccessible(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $internalAdministrator = $this->memberWithRole($operating, OrganizationRole::Administrator);
        $this->assignRoleInOrganization(
            $internalAdministrator,
            $clientOrganization,
            OrganizationRole::Administrator,
        );
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $malformed = $this->createChannel(
            $clientOrganization,
            $internalAdministrator,
            ChannelVisibility::Private,
        );

        foreach ([$internalAdministrator, $client] as $user) {
            ConversationMembership::factory()->create([
                'conversation_id' => $malformed->conversation_id,
                'user_id' => $user,
                'channel_role' => ChannelMembershipRole::Member,
            ]);

            $this->assertFalse($this->access->canRead($user, $malformed));
            $this->assertFalse($this->access->canManage($user, $malformed));
        }
    }

    public function test_public_channels_allow_internal_self_join_while_private_channels_require_an_owner(): void
    {
        $operating = Organization::factory()->operating()->create();
        $creator = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $member = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $public = $this->channels->createInternal($operating, $creator, 'General', ChannelVisibility::Public);
        $private = $this->channels->createInternal($operating, $creator, 'Leadership', ChannelVisibility::Private);

        $joined = $this->channels->join($public, $member);
        $invited = $this->channels->inviteToPrivate($private, $creator, $member);

        $this->assertSame(ChannelMembershipRole::Member, $joined->channel_role);
        $this->assertSame(ChannelMembershipRole::Member, $invited->channel_role);
        $this->assertTrue($this->access->canRead($member, $private));

        $outsider = $this->memberWithRole($operating, OrganizationRole::InternalMember);

        $this->expectException(AuthorizationException::class);

        $this->channels->join($private, $outsider);
    }

    public function test_final_owner_must_transfer_ownership_before_leaving(): void
    {
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $nextOwner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $channel = $this->channels->createInternal($operating, $owner, 'General', ChannelVisibility::Public);
        $this->channels->join($channel, $nextOwner);

        try {
            $this->channels->leave($channel, $owner);
            $this->fail('The final Channel owner left without a transfer.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('membership', $exception->errors());
        }

        $this->channels->promoteOwner($channel, $owner, $nextOwner);
        $this->channels->leave($channel, $owner);

        $this->assertNotNull($channel->conversation->memberships()
            ->where('user_id', $owner->getKey())
            ->sole()
            ->left_at);
    }

    public function test_only_global_or_organization_administrators_can_archive_channels(): void
    {
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $administrator = $this->memberWithRole($operating, OrganizationRole::Administrator);
        $channel = $this->channels->createInternal($operating, $owner, 'General', ChannelVisibility::Public);

        try {
            $this->channels->archive($channel, $owner);
            $this->fail('An ordinary Channel owner archived the Channel.');
        } catch (AuthorizationException) {
            $this->assertNull($channel->conversation->fresh()->archived_at);
        }

        $this->channels->archive($channel, $administrator);

        $this->assertNotNull($channel->conversation->fresh()->archived_at);

        $this->expectException(ValidationException::class);

        $this->channels->rename($channel->fresh(), $administrator, 'Renamed After Archive');
    }

    public function test_client_team_access_comes_from_client_organization_membership_only(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $internal = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $otherClient = User::factory()->create();
        $team = $this->createClientTeamChannel($clientOrganization, $internal);

        $this->assertTrue($this->access->canRead($client, $team));
        $this->assertFalse($this->access->canRead($otherClient, $team));
        $this->assertFalse($this->access->canRead($internal, $team));

        $client->organizationMemberships()
            ->where('organization_id', $clientOrganization->getKey())
            ->update(['status' => MembershipStatus::Suspended]);

        $this->assertFalse($this->access->canRead($client, $team));

        $this->channels->join($team, $internal);

        $this->assertTrue($this->access->canRead($internal, $team));

        $this->expectException(ValidationException::class);

        $this->channels->leave($team, $client);
    }

    public function test_client_users_cannot_be_removed_through_channel_membership(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $administrator = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($administrator, $clientOrganization, OrganizationRole::Administrator);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $team = $this->createClientTeamChannel($clientOrganization, $administrator);

        $this->expectException(ValidationException::class);

        $this->channels->removeInternalMember($team, $administrator, $client);
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

    private function createClientTeamChannel(Organization $organization, User $creator): Channel
    {
        return $this->createChannel($organization, $creator, ChannelVisibility::ClientTeam);
    }

    private function createChannel(
        Organization $organization,
        User $creator,
        ChannelVisibility $visibility,
    ): Channel {
        $conversation = Conversation::factory()->create([
            'organization_id' => $organization,
            'type' => ConversationType::Channel,
            'created_by_user_id' => $creator,
        ]);

        return Channel::query()->create([
            'conversation_id' => $conversation->getKey(),
            'organization_id' => $organization->getKey(),
            'name' => 'Team',
            'slug' => 'team',
            'visibility' => $visibility,
        ])->load(['conversation', 'organization']);
    }
}
