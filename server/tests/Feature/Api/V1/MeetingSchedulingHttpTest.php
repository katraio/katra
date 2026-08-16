<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Conversations\ChannelService;
use App\Conversations\DirectMessageService;
use App\Enums\ChannelVisibility;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class MeetingSchedulingHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bouncer::scope()->remove();
        $this->seed(AuthorizationSeeder::class);
        CarbonImmutable::setTestNow('2026-08-09T12:00:00-04:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Bouncer::scope()->remove();

        parent::tearDown();
    }

    public function test_guest_cannot_use_meeting_resources(): void
    {
        $this->getJson('/api/v1/meetings')->assertUnauthorized();
    }

    public function test_internal_member_can_schedule_a_participant_private_meeting_with_agenda_and_guests(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);

        $this->actingAs($organizer)
            ->getJson("/api/v1/organizations/{$organization->public_id}/meeting-candidates")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $organizer->public_id, 'name' => $organizer->name])
            ->assertJsonFragment(['id' => $participant->public_id, 'name' => $participant->name])
            ->assertJsonMissingPath('data.0.email');

        $response = $this->actingAs($organizer)
            ->postJson("/api/v1/organizations/{$organization->public_id}/meetings", [
                'title' => 'Katra scheduling review',
                'starts_at' => '2026-08-10T10:00:00-04:00',
                'duration_minutes' => 30,
                'desired_outcome' => 'Leave with a bounded implementation plan.',
                'participant_ids' => [$participant->public_id],
                'guest_emails' => ['Guest@example.com'],
                'agenda_items' => [
                    [
                        'title' => 'Confirm the meeting contract',
                        'owner_user_id' => $participant->public_id,
                        'duration_minutes' => 10,
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Katra scheduling review')
            ->assertJsonPath('data.duration_minutes', 30)
            ->assertJsonPath('data.organizer.id', $organizer->public_id)
            ->assertJsonCount(2, 'data.participants')
            ->assertJsonFragment(['id' => $organizer->public_id, 'name' => $organizer->name])
            ->assertJsonFragment(['id' => $participant->public_id, 'name' => $participant->name])
            ->assertJsonPath('data.agenda_items.0.owner.id', $participant->public_id)
            ->assertJsonPath('data.guest_invitations.0.email', 'guest@example.com');

        $meetingId = $response->json('data.id');
        $guestUrl = $response->json('data.guest_link_url');
        parse_str((string) parse_url($guestUrl, PHP_URL_FRAGMENT), $fragment);
        $rawToken = $fragment['token'] ?? '';

        $this->assertNotSame('', $rawToken);
        $this->assertSame(
            hash('sha256', $rawToken),
            DB::table('meetings')->where('public_id', $meetingId)->value('guest_link_token_hash'),
        );
        $this->assertStringNotContainsString(
            $rawToken,
            DB::table('meetings')->where('public_id', $meetingId)->value('guest_link_token'),
        );
        $this->assertDatabaseHas('meeting_invitations', ['email' => 'guest@example.com']);
        $this->assertDatabaseCount('meeting_participants', 2);
        $this->assertDatabaseCount('meeting_agenda_items', 1);

        $this->actingAs($organizer)
            ->getJson('/api/v1/meetings')
            ->assertOk()
            ->assertJsonPath('data.0.guest_link_url', null)
            ->assertJsonCount(0, 'data.0.guest_invitations');
    }

    public function test_internal_member_can_start_an_organizer_only_meeting_immediately(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);

        $this->actingAs($organizer)
            ->postJson("/api/v1/organizations/{$organization->public_id}/meetings/instant", [
                'title' => 'general meeting',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'general meeting')
            ->assertJsonPath('data.starts_at', '2026-08-09T16:00:00.000000Z')
            ->assertJsonPath('data.duration_minutes', 30)
            ->assertJsonPath('data.status', 'live')
            ->assertJsonPath('data.started_at', '2026-08-09T16:00:00.000000Z')
            ->assertJsonCount(1, 'data.participants')
            ->assertJsonPath('data.participants.0.id', $organizer->public_id);

        $this->assertDatabaseCount('meetings', 1);
        $this->assertDatabaseCount('meeting_participants', 1);
    }

    public function test_channel_readers_start_or_join_one_conversation_scoped_live_meeting(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $reader = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $channel = app(ChannelService::class)->createInternal(
            $organization,
            $organizer,
            'General',
            ChannelVisibility::Public,
        );

        $started = $this->actingAs($organizer)
            ->postJson("/api/v1/conversations/{$channel->conversation->public_id}/meeting", [
                'title' => 'General meeting',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'live')
            ->assertJsonPath('data.conversation_id', $channel->conversation->public_id)
            ->assertJsonCount(1, 'data.participants');

        $meetingId = $started->json('data.id');
        $this->actingAs($reader)
            ->getJson('/api/v1/channels')
            ->assertOk()
            ->assertJsonPath('data.0.live_meeting.id', $meetingId)
            ->assertJsonPath('data.0.live_meeting.organizer.id', $organizer->public_id);

        $joined = $this->actingAs($reader)
            ->postJson("/api/v1/conversations/{$channel->conversation->public_id}/meeting", [
                'title' => 'Ignored duplicate title',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $meetingId)
            ->assertJsonCount(2, 'data.participants');

        $this->actingAs($reader)
            ->postJson("/api/v1/meetings/{$meetingId}/join")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $reader->public_id,
                'joined_at' => '2026-08-09T16:00:00.000000Z',
                'left_at' => null,
            ]);

        $this->assertDatabaseCount('meetings', 1);
        $this->assertDatabaseCount('meeting_participants', 2);
        $this->assertSame($meetingId, $joined->json('data.id'));
    }

    public function test_direct_message_participants_share_one_live_meeting_and_outsiders_cannot_probe_it(): void
    {
        $organization = Organization::factory()->operating()->create();
        $first = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $second = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $directMessage = app(DirectMessageService::class)->create($organization, $first, [$second]);
        $conversationId = $directMessage->conversation->public_id;

        $started = $this->actingAs($first)
            ->postJson("/api/v1/conversations/{$conversationId}/meeting", [
                'title' => 'Direct Message meeting',
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.participants');

        $this->actingAs($second)
            ->getJson('/api/v1/direct-messages')
            ->assertOk()
            ->assertJsonPath('data.0.live_meeting.id', $started->json('data.id'));

        $this->actingAs($outsider)
            ->postJson("/api/v1/conversations/{$conversationId}/meeting", [
                'title' => 'Unauthorized meeting',
            ])
            ->assertNotFound();
    }

    public function test_organizer_controls_room_lifecycle_and_participants_record_attendance(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $meeting = $this->schedule($organization, $organizer, $participant);

        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/join")
            ->assertConflict()
            ->assertJsonPath('message', 'The organizer has not started this meeting yet.');
        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/start")
            ->assertForbidden();

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'live')
            ->assertJsonPath('data.started_at', '2026-08-09T16:00:00.000000Z');

        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/join")
            ->assertOk()
            ->assertJsonPath('data.status', 'live')
            ->assertJsonFragment([
                'id' => $participant->public_id,
                'joined_at' => '2026-08-09T16:00:00.000000Z',
                'left_at' => null,
            ]);
        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/join")
            ->assertOk();

        CarbonImmutable::setTestNow('2026-08-09T12:05:00-04:00');
        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/leave")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $participant->public_id,
                'joined_at' => '2026-08-09T16:00:00.000000Z',
                'left_at' => '2026-08-09T16:05:00.000000Z',
            ]);

        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/end")
            ->assertForbidden();

        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/join")
            ->assertOk();
        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/leave")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.ended_at', '2026-08-09T16:05:00.000000Z')
            ->assertJsonFragment([
                'id' => $participant->public_id,
                'left_at' => '2026-08-09T16:05:00.000000Z',
            ]);
        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/join")
            ->assertConflict()
            ->assertJsonPath('message', 'This meeting is no longer open.');
    }

    public function test_only_organizer_can_cancel_scheduled_meeting_and_outsider_cannot_probe_commands(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($organization, OrganizationRole::Administrator);
        $meeting = $this->schedule($organization, $organizer, $participant);

        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/cancel")
            ->assertForbidden();
        $this->actingAs($outsider)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/start")
            ->assertNotFound();

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_at', '2026-08-09T16:00:00.000000Z');
        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_organizer_can_add_an_eligible_participant_idempotently(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);

        $meetingId = $this->actingAs($organizer)
            ->postJson("/api/v1/organizations/{$organization->public_id}/meetings/instant", [
                'title' => 'general meeting',
            ])
            ->assertCreated()
            ->json('data.id');

        foreach (range(1, 2) as $_attempt) {
            $this->actingAs($organizer)
                ->postJson("/api/v1/meetings/{$meetingId}/participants", [
                    'participant_ids' => [$participant->public_id],
                ])
                ->assertOk()
                ->assertJsonCount(2, 'data.participants')
                ->assertJsonFragment([
                    'id' => $participant->public_id,
                    'name' => $participant->name,
                ]);
        }

        $this->assertDatabaseCount('meeting_participants', 2);
        $this->actingAs($participant)
            ->getJson('/api/v1/meetings')
            ->assertOk()
            ->assertJsonPath('data.0.id', $meetingId);
    }

    public function test_only_the_organizer_can_add_eligible_participants(): void
    {
        $organization = Organization::factory()->operating()->create();
        $otherOrganization = Organization::factory()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $candidate = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($otherOrganization, OrganizationRole::ClientMember);
        $meeting = $this->schedule($organization, $organizer, $participant);

        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/participants", [
                'participant_ids' => [$candidate->public_id],
            ])
            ->assertForbidden();

        $this->actingAs($outsider)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/participants", [
                'participant_ids' => [$candidate->public_id],
            ])
            ->assertNotFound();

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/participants", [
                'participant_ids' => [$outsider->public_id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('participant_ids');

        $this->assertDatabaseCount('meeting_participants', 2);
    }

    public function test_only_recorded_participants_can_discover_a_meeting(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($organization, OrganizationRole::Administrator);
        $meeting = $this->schedule($organization, $organizer, $participant);

        $this->actingAs($participant)
            ->getJson('/api/v1/meetings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $meeting->public_id)
            ->assertJsonPath('data.0.guest_link_url', null)
            ->assertJsonCount(0, 'data.0.guest_invitations');

        $this->actingAs($outsider)
            ->getJson('/api/v1/meetings')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($outsider)
            ->getJson("/api/v1/meetings/{$meeting->public_id}")
            ->assertNotFound();
    }

    public function test_suspended_members_lose_recorded_meeting_access(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $meeting = $this->schedule($organization, $organizer, $participant);

        OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $participant->getKey())
            ->update([
                'status' => MembershipStatus::Suspended->value,
                'suspended_at' => now(),
            ]);

        $this->actingAs($participant)
            ->getJson('/api/v1/meetings')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($participant)
            ->getJson("/api/v1/meetings/{$meeting->public_id}")
            ->assertNotFound();

        OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $organizer->getKey())
            ->update([
                'status' => MembershipStatus::Suspended->value,
                'suspended_at' => now(),
            ]);

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/start")
            ->assertNotFound();
    }

    public function test_recorded_participant_can_export_a_private_calendar_event_without_invitation_secrets(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($organization, OrganizationRole::Administrator);

        $response = $this->actingAs($organizer)
            ->postJson("/api/v1/organizations/{$organization->public_id}/meetings", [
                'title' => 'Planning, review; one',
                'starts_at' => '2026-08-10T10:00:00-04:00',
                'duration_minutes' => 30,
                'desired_outcome' => "Decide safely.\nRecord next steps.",
                'participant_ids' => [$participant->public_id],
                'guest_emails' => ['calendar-guest@example.com'],
                'agenda_items' => [[
                    'title' => 'Scope, review',
                    'owner_user_id' => $participant->public_id,
                    'duration_minutes' => 10,
                ]],
            ])
            ->assertCreated();
        $meetingId = $response->json('data.id');
        $guestLink = $response->json('data.guest_link_url');

        $calendar = $this->actingAs($participant)
            ->get("/api/v1/meetings/{$meetingId}/calendar.ics")
            ->assertOk()
            ->assertHeader('content-type', 'text/calendar; charset=utf-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('content-disposition', 'attachment; filename="planning-review-one.ics"')
            ->getContent();

        $this->assertStringContainsString("BEGIN:VCALENDAR\r\n", $calendar);
        $this->assertStringContainsString("UID:{$meetingId}@katra\r\n", $calendar);
        $this->assertStringContainsString("DTSTART:20260810T140000Z\r\n", $calendar);
        $this->assertStringContainsString("DTEND:20260810T143000Z\r\n", $calendar);
        $this->assertStringContainsString("SUMMARY:Planning\\, review\\; one\r\n", $calendar);
        $this->assertStringContainsString('DESCRIPTION:Desired outcome:\\nDecide safely.\\nRecord next steps.', $calendar);
        $this->assertStringNotContainsString('calendar-guest@example.com', $calendar);
        $this->assertStringNotContainsString((string) $guestLink, $calendar);

        $this->actingAs($outsider)
            ->get("/api/v1/meetings/{$meetingId}/calendar.ics")
            ->assertNotFound();
    }

    public function test_clients_cannot_create_or_enumerate_meeting_candidates(): void
    {
        $clientOrganization = Organization::factory()->create();
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);

        $this->actingAs($client)
            ->getJson("/api/v1/organizations/{$clientOrganization->public_id}/meeting-candidates")
            ->assertNotFound();
        $this->actingAs($client)
            ->postJson("/api/v1/organizations/{$clientOrganization->public_id}/meetings", [
                'title' => 'Unauthorized meeting',
                'starts_at' => '2026-08-10T10:00:00-04:00',
                'duration_minutes' => 30,
                'guest_emails' => ['guest@example.com'],
            ])
            ->assertNotFound();
    }

    public function test_cross_organization_participants_and_nonparticipant_agenda_owners_fail_closed(): void
    {
        $organization = Organization::factory()->operating()->create();
        $otherOrganization = Organization::factory()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($otherOrganization, OrganizationRole::ClientMember);

        $base = [
            'title' => 'Boundary review',
            'starts_at' => '2026-08-10T10:00:00-04:00',
            'duration_minutes' => 30,
        ];

        $this->actingAs($organizer)
            ->postJson("/api/v1/organizations/{$organization->public_id}/meetings", $base + [
                'participant_ids' => [$outsider->public_id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('participant_ids');

        $this->actingAs($organizer)
            ->postJson("/api/v1/organizations/{$organization->public_id}/meetings", $base + [
                'participant_ids' => [$participant->public_id],
                'agenda_items' => [[
                    'title' => 'Unauthorized ownership',
                    'owner_user_id' => $outsider->public_id,
                    'duration_minutes' => 10,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('agenda_items.0.owner_user_id');
    }

    public function test_meeting_creation_rejects_past_starts_and_empty_invitation_sets(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->memberWithRole($organization, OrganizationRole::InternalMember);

        $this->actingAs($organizer)
            ->postJson("/api/v1/organizations/{$organization->public_id}/meetings", [
                'title' => 'Past meeting',
                'starts_at' => '2026-08-08T10:00:00-04:00',
                'duration_minutes' => 30,
                'guest_emails' => ['guest@example.com'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->actingAs($organizer)
            ->postJson("/api/v1/organizations/{$organization->public_id}/meetings", [
                'title' => 'Empty meeting',
                'starts_at' => '2026-08-10T10:00:00-04:00',
                'duration_minutes' => 30,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('participant_ids');
    }

    private function schedule(Organization $organization, User $organizer, User $participant): Meeting
    {
        $response = $this->actingAs($organizer)
            ->postJson("/api/v1/organizations/{$organization->public_id}/meetings", [
                'title' => 'Participant-private meeting',
                'starts_at' => '2026-08-10T10:00:00-04:00',
                'duration_minutes' => 30,
                'participant_ids' => [$participant->public_id],
            ])
            ->assertCreated();

        return Meeting::query()->where('public_id', $response->json('data.id'))->firstOrFail();
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
