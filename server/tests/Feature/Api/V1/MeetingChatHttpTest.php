<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Enums\OrganizationRole;
use App\Events\MeetingMessageCreated;
use App\Events\MeetingMessageReactionChanged;
use App\Events\MeetingRoomReactionSent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class MeetingChatHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bouncer::scope()->remove();
        $this->seed(AuthorizationSeeder::class);
        CarbonImmutable::setTestNow('2026-08-10T11:00:00-04:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Bouncer::scope()->remove();

        parent::tearDown();
    }

    public function test_guest_cannot_use_meeting_chat(): void
    {
        $meeting = '01J00000000000000000000000';
        $this->getJson("/api/v1/meetings/{$meeting}/messages")->assertUnauthorized();
        $this->postJson("/api/v1/meetings/{$meeting}/messages", [])->assertUnauthorized();
    }

    public function test_participants_exchange_ordered_idempotent_private_chat(): void
    {
        Event::fake([MeetingMessageCreated::class]);
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->member($organization);
        $participant = $this->member($organization);
        $outsider = $this->member($organization, OrganizationRole::Administrator);
        $meetingId = $this->liveMeeting($organization, $organizer, $participant);
        $firstKey = (string) Str::uuid();

        $first = $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meetingId}/messages", [
                'body' => 'The meeting chat is participant-private.',
                'idempotency_key' => $firstKey,
            ])
            ->assertCreated()
            ->assertJsonPath('data.sequence', 1)
            ->assertJsonPath('data.author.id', $participant->public_id)
            ->assertJsonPath('data.body', 'The meeting chat is participant-private.');

        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meetingId}/messages", [
                'body' => 'The meeting chat is participant-private.',
                'idempotency_key' => $firstKey,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meetingId}/messages", [
                'body' => 'Conflicting retry.',
                'idempotency_key' => $firstKey,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        foreach (['Second message.', 'Third message.'] as $body) {
            $this->actingAs($organizer)
                ->postJson("/api/v1/meetings/{$meetingId}/messages", [
                    'body' => $body,
                    'idempotency_key' => (string) Str::uuid(),
                ])
                ->assertCreated();
        }

        $this->actingAs($participant)
            ->getJson("/api/v1/meetings/{$meetingId}/messages?limit=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.sequence', 2)
            ->assertJsonPath('data.1.sequence', 3)
            ->assertJsonPath('meta.pagination.has_more', true);

        $this->actingAs($participant)
            ->getJson("/api/v1/meetings/{$meetingId}/messages?after_sequence=1")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.mode', 'after');

        $this->actingAs($outsider)
            ->getJson("/api/v1/meetings/{$meetingId}/messages")
            ->assertNotFound();

        $this->assertDatabaseCount('meeting_messages', 3);
        Event::assertDispatchedTimes(MeetingMessageCreated::class, 3);
        Event::assertDispatched(MeetingMessageCreated::class, function (MeetingMessageCreated $event) use ($meetingId): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()->name === "presence-meetings.{$meetingId}"
                && $event->broadcastAs() === 'meeting.message.created.v1'
                && $payload['meeting_id'] === $meetingId
                && ! array_key_exists('body', $payload)
                && ! array_key_exists('author_user_id', $payload);
        });
    }

    public function test_message_reactions_are_idempotent_and_terminal_meetings_are_read_only(): void
    {
        Event::fake([MeetingMessageReactionChanged::class]);
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->member($organization);
        $participant = $this->member($organization);
        $meetingId = $this->liveMeeting($organization, $organizer, $participant);
        $messageId = $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meetingId}/messages", [
                'body' => 'React to this durable message.',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->json('data.id');

        $reactionUrl = "/api/v1/meetings/{$meetingId}/messages/{$messageId}/reactions";
        $this->actingAs($participant)
            ->putJson($reactionUrl, ['kind' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.reactions.0.kind', 'approve')
            ->assertJsonPath('data.reactions.0.count', 1)
            ->assertJsonPath('data.reactions.0.reacted_by_current_user', true);
        $this->actingAs($participant)->putJson($reactionUrl, ['kind' => 'approve'])->assertOk();

        $this->actingAs($organizer)
            ->putJson($reactionUrl, ['kind' => 'support'])
            ->assertOk()
            ->assertJsonCount(2, 'data.reactions');

        $this->actingAs($participant)
            ->deleteJson($reactionUrl, ['kind' => 'approve'])
            ->assertOk()
            ->assertJsonCount(1, 'data.reactions')
            ->assertJsonPath('data.reactions.0.kind', 'support');
        $this->actingAs($participant)->deleteJson($reactionUrl, ['kind' => 'approve'])->assertOk();

        $this->assertDatabaseCount('meeting_message_reactions', 1);
        Event::assertDispatchedTimes(MeetingMessageReactionChanged::class, 3);
        Event::assertDispatched(MeetingMessageReactionChanged::class, function (MeetingMessageReactionChanged $event) use ($meetingId, $messageId): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()->name === "presence-meetings.{$meetingId}"
                && $payload['message_id'] === $messageId
                && ! array_key_exists('kind', $payload)
                && ! array_key_exists('actor_user_id', $payload);
        });

        $this->actingAs($organizer)->postJson("/api/v1/meetings/{$meetingId}/end")->assertOk();

        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meetingId}/messages", [
                'body' => 'Too late.',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meeting');
        $this->actingAs($participant)
            ->putJson($reactionUrl, ['kind' => 'approve'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meeting');

        $this->actingAs($participant)
            ->getJson("/api/v1/meetings/{$meetingId}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reactions.0.kind', 'support');
    }

    public function test_live_participants_send_bounded_transient_room_reactions(): void
    {
        Event::fake([MeetingRoomReactionSent::class]);
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->member($organization);
        $participant = $this->member($organization);
        $outsider = $this->member($organization);
        $meetingId = $this->liveMeeting($organization, $organizer, $participant);

        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meetingId}/reactions", ['kind' => 'celebrate'])
            ->assertOk()
            ->assertJsonPath('data.accepted', true);
        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meetingId}/reactions", ['kind' => 'emoji-party'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kind');
        $this->actingAs($outsider)
            ->postJson("/api/v1/meetings/{$meetingId}/reactions", ['kind' => 'support'])
            ->assertNotFound();

        Event::assertDispatched(MeetingRoomReactionSent::class, function (MeetingRoomReactionSent $event) use ($meetingId, $participant): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()->name === "presence-meetings.{$meetingId}"
                && $event->broadcastAs() === 'meeting.room.reaction.sent.v1'
                && $payload['actor_user_id'] === $participant->public_id
                && $payload['kind'] === 'celebrate';
        });
        $this->assertDatabaseCount('meeting_messages', 0);
        $this->assertDatabaseCount('meeting_message_reactions', 0);
    }

    private function liveMeeting(Organization $organization, User $organizer, User $participant): string
    {
        return $this->actingAs($organizer)
            ->postJson("/api/v1/organizations/{$organization->public_id}/meetings/instant", [
                'title' => 'Meeting chat review',
                'participant_ids' => [$participant->public_id],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function member(
        Organization $organization,
        OrganizationRole $role = OrganizationRole::InternalMember,
    ): User {
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
