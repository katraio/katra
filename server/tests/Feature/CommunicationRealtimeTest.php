<?php

namespace Tests\Feature;

use App\Auth\OrganizationAuthorization;
use App\Conversations\ChannelService;
use App\Conversations\ConversationAccess;
use App\Conversations\ConversationMessageService;
use App\Conversations\ConversationReactionService;
use App\Conversations\ConversationReadService;
use App\Conversations\DirectMessageService;
use App\Enums\ChannelMembershipRole;
use App\Enums\ChannelVisibility;
use App\Enums\ConversationType;
use App\Enums\MeetingOutcomeKind;
use App\Enums\OrganizationRole;
use App\Events\AttentionItemChanged;
use App\Events\ConversationMessageChanged;
use App\Events\ConversationMessageCreated;
use App\Events\ConversationReactionChanged;
use App\Events\ConversationReadPositionAdvanced;
use App\Events\MeetingAccessChanged;
use App\Events\MeetingOutcomeCreated;
use App\Events\MeetingParticipantAccessChanged;
use App\Events\MeetingStateChanged;
use App\Meetings\MeetingOutcomeService;
use App\Meetings\MeetingParticipantAccessService;
use App\Meetings\MeetingRoomService;
use App\Meetings\MeetingService;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationMembership;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class CommunicationRealtimeTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Bouncer::scope()->remove();
        $this->seed(AuthorizationSeeder::class);
        Broadcast::setDefaultDriver('reverb');
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
    }

    protected function tearDown(): void
    {
        Bouncer::scope()->remove();

        parent::tearDown();
    }

    public function test_new_committed_state_dispatches_bounded_events_once(): void
    {
        Event::fake([
            ConversationMessageCreated::class,
            ConversationMessageChanged::class,
            ConversationReactionChanged::class,
            ConversationReadPositionAdvanced::class,
            AttentionItemChanged::class,
        ]);
        $organization = Organization::factory()->operating()->create();
        $author = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $directMessage = app(DirectMessageService::class)->create($organization, $author, [$participant]);
        $conversationId = $directMessage->conversation->public_id;
        $messages = app(ConversationMessageService::class);

        $message = $messages->send(
            $author,
            $conversationId,
            'Committed once.',
            'realtime-message-1',
            null,
            [],
            [$participant->public_id],
        );
        $messages->send(
            $author,
            $conversationId,
            'Committed once.',
            'realtime-message-1',
            null,
            [],
            [$participant->public_id],
        );

        $reactions = app(ConversationReactionService::class);
        $reactions->add($author, $conversationId, $message->public_id, 'approve');
        $reactions->add($author, $conversationId, $message->public_id, 'approve');
        $reactions->remove($author, $conversationId, $message->public_id, 'approve');
        $reactions->remove($author, $conversationId, $message->public_id, 'approve');

        $reads = app(ConversationReadService::class);
        $reads->advance($participant, $conversationId, 1);
        $reads->advance($participant, $conversationId, 0);

        $messages->edit($author, $conversationId, $message->public_id, 'Corrected once.');
        $messages->delete($author, $conversationId, $message->public_id);

        Event::assertDispatchedTimes(ConversationMessageCreated::class, 1);
        Event::assertDispatchedTimes(ConversationMessageChanged::class, 2);
        Event::assertDispatchedTimes(ConversationReactionChanged::class, 2);
        Event::assertDispatchedTimes(ConversationReadPositionAdvanced::class, 1);
        Event::assertDispatchedTimes(AttentionItemChanged::class, 1);

        Event::assertDispatched(ConversationMessageCreated::class, function ($event) use ($conversationId, $message, $participant): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastOn() instanceof PrivateChannel
                && $event->broadcastAs() === 'conversation.message.created.v1'
                && $payload['conversation_id'] === $conversationId
                && $payload['message_id'] === $message->public_id
                && $payload['sequence'] === 1
                && $payload['mentioned_user_ids'] === []
                && $payload['attention_user_ids'] === [$participant->public_id]
                && $payload['version'] === 1
                && ! array_key_exists('body', $payload);
        });

        Event::assertDispatched(ConversationReactionChanged::class, function ($event) use ($message): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastAs() === 'conversation.reaction.changed.v1'
                && $payload['message_id'] === $message->public_id
                && $payload['message_sequence'] === 1
                && ! array_key_exists('actor_user_id', $payload)
                && ! array_key_exists('reactions', $payload);
        });

        Event::assertDispatched(ConversationMessageChanged::class, function ($event) use ($message): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastAs() === 'conversation.message.changed.v1'
                && $payload['message_id'] === $message->public_id
                && $payload['message_sequence'] === 1
                && in_array($payload['operation'], ['edited', 'deleted'], true)
                && ! array_key_exists('body', $payload)
                && ! array_key_exists('actor_user_id', $payload);
        });

        Event::assertDispatched(ConversationReadPositionAdvanced::class, function ($event) use ($participant): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()->name === "private-users.{$participant->public_id}"
                && $event->broadcastAs() === 'conversation.read-position.advanced.v1'
                && $payload['last_read_sequence'] === 1
                && $payload['unread_count'] === 0
                && $payload['mention_count'] === 0;
        });
    }

    public function test_broadcast_authorization_matches_participant_and_user_boundaries(): void
    {
        $organization = Organization::factory()->operating()->create();
        $first = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $second = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($organization, OrganizationRole::Administrator);
        $directMessage = app(DirectMessageService::class)->create($organization, $first, [$second]);
        $conversationId = $directMessage->conversation->public_id;

        $this->actingAs($second);
        $this->assertSame($conversationId, app(ConversationAccess::class)->resolveReadable($second, $conversationId)->public_id);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-conversations.{$conversationId}",
        ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($second)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "presence-conversations.{$conversationId}",
            ])
            ->assertOk()
            ->assertJsonStructure(['auth', 'channel_data']);

        $this->actingAs($outsider)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-conversations.{$conversationId}",
            ])
            ->assertForbidden();

        $this->actingAs($second)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-users.{$second->public_id}",
            ])
            ->assertOk();

        $this->actingAs($second)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-users.{$first->public_id}",
            ])
            ->assertForbidden();
    }

    public function test_meeting_creation_dispatches_content_free_access_hints_after_commit(): void
    {
        Event::fake([MeetingAccessChanged::class]);
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);

        $meeting = app(MeetingService::class)->create(
            $organization,
            $organizer,
            [$participant],
            'Private planning review',
            CarbonImmutable::now()->addDay(),
            30,
            'Agree on a private next step.',
            ['guest@example.com'],
            [],
        );

        Event::assertDispatchedTimes(MeetingAccessChanged::class, 2);

        foreach ([$organizer, $participant] as $recipient) {
            Event::assertDispatched(MeetingAccessChanged::class, function ($event) use ($meeting, $recipient): bool {
                $payload = $event->broadcastWith();

                return $event->broadcastOn()->name === "private-users.{$recipient->public_id}"
                    && $event->broadcastAs() === 'meeting.access.changed.v1'
                    && $payload['meeting_id'] === $meeting->public_id
                    && $payload['operation'] === 'granted'
                    && $payload['version'] === 1
                    && ! array_key_exists('title', $payload)
                    && ! array_key_exists('organization_id', $payload)
                    && ! array_key_exists('participant_ids', $payload)
                    && ! array_key_exists('starts_at', $payload);
            });
        }
    }

    public function test_adding_a_meeting_participant_dispatches_one_content_free_access_hint(): void
    {
        Event::fake([MeetingAccessChanged::class]);
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $meetings = app(MeetingService::class);
        $meeting = $meetings->create(
            $organization,
            $organizer,
            [],
            'Live planning review',
            CarbonImmutable::now(),
            30,
            null,
            [],
            [],
        );
        Event::fake([MeetingAccessChanged::class]);

        $meetings->addParticipants($meeting, $organizer, [$participant]);
        $meetings->addParticipants($meeting, $organizer, [$participant]);

        Event::assertDispatchedTimes(MeetingAccessChanged::class, 1);
        Event::assertDispatched(MeetingAccessChanged::class, function ($event) use ($meeting, $participant): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()->name === "private-users.{$participant->public_id}"
                && $payload['meeting_id'] === $meeting->public_id
                && $payload['operation'] === 'granted'
                && ! array_key_exists('title', $payload)
                && ! array_key_exists('participant_ids', $payload);
        });
    }

    public function test_meeting_state_changes_dispatch_to_the_room_and_each_participant(): void
    {
        Event::fake([MeetingStateChanged::class]);
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $channel = app(ChannelService::class)->createInternal(
            $organization,
            $organizer,
            'Meeting source',
            ChannelVisibility::Public,
        );
        $meeting = app(MeetingService::class)->create(
            $organization,
            $organizer,
            [$participant],
            'Shared room review',
            CarbonImmutable::now()->addHour(),
            30,
            null,
            [],
            [],
            false,
            $channel->conversation,
        );

        app(MeetingRoomService::class)->start($meeting, $organizer);

        Event::assertDispatchedTimes(MeetingStateChanged::class, 1);
        Event::assertDispatched(MeetingStateChanged::class, function (MeetingStateChanged $event) use ($meeting, $organizer, $participant, $channel): bool {
            $channelNames = collect($event->broadcastOn())->map->name->all();
            $payload = $event->broadcastWith();

            return in_array("presence-meetings.{$meeting->public_id}", $channelNames, true)
                && in_array("private-users.{$organizer->public_id}", $channelNames, true)
                && in_array("private-users.{$participant->public_id}", $channelNames, true)
                && in_array("private-conversations.{$channel->conversation->public_id}", $channelNames, true)
                && $event->broadcastAs() === 'meeting.state.changed.v1'
                && $payload['meeting_id'] === $meeting->public_id
                && $payload['conversation_id'] === $channel->conversation->public_id
                && $payload['status'] === 'live'
                && ! array_key_exists('title', $payload)
                && ! array_key_exists('participant_ids', $payload);
        });
    }

    public function test_participant_removal_dispatches_one_content_free_room_reconciliation_hint(): void
    {
        Event::fake([MeetingAccessChanged::class, MeetingParticipantAccessChanged::class]);
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $meeting = app(MeetingService::class)->create(
            $organization,
            $organizer,
            [$participant],
            'Participant reconciliation review',
            CarbonImmutable::now(),
            30,
            null,
            [],
            [],
            true,
        );
        $record = $meeting->participants()->where('user_id', $participant->getKey())->firstOrFail();
        Event::fake([MeetingAccessChanged::class, MeetingParticipantAccessChanged::class]);

        app(MeetingParticipantAccessService::class)->remove($meeting, $record, $organizer, false);

        Event::assertDispatchedTimes(MeetingParticipantAccessChanged::class, 1);
        Event::assertDispatched(MeetingParticipantAccessChanged::class, function (MeetingParticipantAccessChanged $event) use ($meeting, $record): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastOn() instanceof PresenceChannel
                && $event->broadcastOn()->name === "presence-meetings.{$meeting->public_id}"
                && $event->broadcastAs() === 'meeting.participant.access.changed.v1'
                && $payload['meeting_id'] === $meeting->public_id
                && $payload['participant_id'] === $record->public_id
                && $payload['operation'] === 'removed'
                && $payload['version'] === 1
                && ! array_key_exists('user_id', $payload)
                && ! array_key_exists('display_name', $payload)
                && ! array_key_exists('email', $payload);
        });
        Event::assertDispatched(MeetingAccessChanged::class, fn (MeetingAccessChanged $event): bool => $event->broadcastWith()['operation'] === 'revoked');
    }

    public function test_meeting_outcomes_dispatch_content_free_hints_to_the_room_and_participants(): void
    {
        Event::fake([MeetingOutcomeCreated::class]);
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $meeting = app(MeetingService::class)->create(
            $organization,
            $organizer,
            [$participant],
            'Outcome event review',
            CarbonImmutable::now(),
            30,
            null,
            [],
            [],
            true,
        );

        $outcome = app(MeetingOutcomeService::class)->create(
            $meeting,
            $participant,
            MeetingOutcomeKind::Decision,
            'Keep realtime payloads content-free.',
            null,
        );

        Event::assertDispatchedTimes(MeetingOutcomeCreated::class, 1);
        Event::assertDispatched(MeetingOutcomeCreated::class, function (MeetingOutcomeCreated $event) use ($meeting, $outcome, $organizer, $participant): bool {
            $channelNames = collect($event->broadcastOn())->map->name->all();
            $payload = $event->broadcastWith();

            return in_array("presence-meetings.{$meeting->public_id}", $channelNames, true)
                && in_array("private-users.{$organizer->public_id}", $channelNames, true)
                && in_array("private-users.{$participant->public_id}", $channelNames, true)
                && $event->broadcastAs() === 'meeting.outcome.created.v1'
                && $payload['meeting_id'] === $meeting->public_id
                && $payload['outcome_id'] === $outcome->public_id
                && ! array_key_exists('body', $payload)
                && ! array_key_exists('author_user_id', $payload)
                && ! array_key_exists('assignee_user_id', $payload);
        });
    }

    public function test_meeting_presence_channel_requires_recorded_participation(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($organization, OrganizationRole::Administrator);
        $meeting = app(MeetingService::class)->create(
            $organization,
            $organizer,
            [$participant],
            'Presence boundary review',
            CarbonImmutable::now()->addHour(),
            30,
            null,
            [],
            [],
        );

        $this->actingAs($participant)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "presence-meetings.{$meeting->public_id}",
            ])
            ->assertOk();

        $this->actingAs($outsider)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "presence-meetings.{$meeting->public_id}",
            ])
            ->assertForbidden();
    }

    public function test_broadcast_authorization_reuses_each_channel_read_boundary(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $internal = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $channels = app(ChannelService::class);
        $public = $channels->createInternal($operating, $owner, 'Announcements', ChannelVisibility::Public);
        $private = $channels->createInternal($operating, $owner, 'Leadership', ChannelVisibility::Private);
        $clientTeam = $this->createClientTeamChannel($clientOrganization, $owner);

        ConversationMembership::factory()->create([
            'conversation_id' => $public->conversation_id,
            'user_id' => $client,
            'channel_role' => ChannelMembershipRole::Member,
        ]);

        $this->assertConversationSubscriptionAllowed($internal, $public->conversation->public_id);
        $this->assertConversationSubscriptionDenied($client, $public->conversation->public_id);
        $this->assertConversationSubscriptionAllowed($owner, $private->conversation->public_id);
        $this->assertConversationSubscriptionDenied($internal, $private->conversation->public_id);
        $this->assertConversationSubscriptionAllowed($client, $clientTeam->conversation->public_id);
        $this->assertConversationSubscriptionDenied($internal, $clientTeam->conversation->public_id);

        $channels->join($clientTeam, $internal);

        $this->assertConversationSubscriptionAllowed($internal, $clientTeam->conversation->public_id);
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

    private function createClientTeamChannel(Organization $organization, User $creator): Channel
    {
        $conversation = Conversation::factory()->create([
            'organization_id' => $organization,
            'type' => ConversationType::Channel,
            'created_by_user_id' => $creator,
        ]);

        return Channel::query()->create([
            'conversation_id' => $conversation->getKey(),
            'organization_id' => $organization->getKey(),
            'name' => 'Client Team',
            'slug' => 'client-team',
            'visibility' => ChannelVisibility::ClientTeam,
        ])->load(['conversation', 'organization']);
    }

    private function assertConversationSubscriptionAllowed(User $user, string $conversationId): void
    {
        $this->actingAs($user)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-conversations.{$conversationId}",
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    private function assertConversationSubscriptionDenied(User $user, string $conversationId): void
    {
        $this->actingAs($user)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-conversations.{$conversationId}",
            ])
            ->assertForbidden();
    }
}
