<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Enums\MeetingOutcomeKind;
use App\Enums\OrganizationRole;
use App\Events\MeetingRoomReactionSent;
use App\Meetings\MeetingGuestAccess;
use App\Meetings\MeetingService;
use App\Models\Meeting;
use App\Models\MeetingGuestSession;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class MeetingGuestAdmissionHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bouncer::scope()->remove();
        $this->seed(AuthorizationSeeder::class);
        CarbonImmutable::setTestNow('2026-08-10T15:00:00-04:00');
        RateLimiter::clear('meeting-guest-inspection');
        RateLimiter::clear('meeting-guest-admission');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Bouncer::scope()->remove();
        parent::tearDown();
    }

    public function test_copied_link_inspection_and_admission_are_meeting_scoped_and_idempotent(): void
    {
        [$meeting, $organizer] = $this->meeting();
        [$otherMeeting] = $this->meeting();
        $token = $meeting->guest_link_token;

        $this->postJson("/api/v1/meeting-guests/{$meeting->public_id}/inspect", ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.title', 'Guest admission review')
            ->assertJsonPath('data.organizer.name', $organizer->name)
            ->assertJsonMissingPath('data.desired_outcome')
            ->assertJsonMissingPath('data.organization.id');
        $this->postJson("/api/v1/meeting-guests/{$otherMeeting->public_id}/inspect", ['token' => $token])
            ->assertNotFound()->assertExactJson(['message' => 'This meeting link is unavailable or has expired.']);
        $this->postJson("/api/v1/meeting-guests/{$meeting->public_id}/inspect", ['token' => 'malformed'])
            ->assertNotFound()->assertExactJson(['message' => 'This meeting link is unavailable or has expired.']);

        $key = (string) Str::uuid();
        $admitted = $this->postJson("/api/v1/meeting-guests/{$meeting->public_id}/admit", [
            'token' => $token,
            'display_name' => '  Jamie   Guest  ',
            'idempotency_key' => $key,
        ])->assertCreated()
            ->assertJsonPath('data.participant.name', 'Jamie Guest')
            ->assertJsonPath('data.meeting.status', 'live')
            ->assertJsonPath('data.meeting.organization.id', null);
        $sessionToken = $admitted->json('data.session_token');
        $participantId = $admitted->json('data.participant.id');

        $this->postJson("/api/v1/meeting-guests/{$meeting->public_id}/admit", [
            'token' => $token,
            'display_name' => 'Jamie Guest',
            'idempotency_key' => $key,
        ])->assertOk()
            ->assertJsonPath('data.session_token', $sessionToken)
            ->assertJsonPath('data.participant.id', $participantId);
        $this->postJson("/api/v1/meeting-guests/{$meeting->public_id}/admit", [
            'token' => $token,
            'display_name' => 'Different Guest',
            'idempotency_key' => $key,
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $second = $this->postJson("/api/v1/meeting-guests/{$meeting->public_id}/admit", [
            'token' => $token,
            'display_name' => 'Taylor Guest',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $this->assertNotSame($participantId, $second->json('data.participant.id'));
        $this->assertDatabaseCount('meeting_guest_sessions', 2);
        $this->assertDatabaseCount('meeting_participants', 4);

        $this->withToken($sessionToken)->getJson('/api/v1/meeting-guest/session')
            ->assertOk()->assertJsonPath('data.id', $meeting->public_id);
        $this->withToken($sessionToken)->getJson('/api/v1/meetings')->assertUnauthorized();
    }

    public function test_guest_session_uses_existing_room_content_and_presence_without_ordinary_authority(): void
    {
        Event::fake([MeetingRoomReactionSent::class]);
        [$meeting, $organizer] = $this->meeting();
        $admission = $this->admit($meeting, 'Morgan Visitor');
        $sessionToken = $admission['token'];
        $guestId = $admission['participant_id'];

        $this->withToken($sessionToken)->postJson('/api/v1/meeting-guest/join')->assertOk()
            ->assertJsonFragment(['id' => $guestId, 'name' => 'Morgan Visitor', 'kind' => 'guest']);
        $message = $this->withToken($sessionToken)->postJson('/api/v1/meeting-guest/messages', [
            'body' => 'Guest chat stays inside this meeting.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()
            ->assertJsonPath('data.author.id', $guestId)
            ->assertJsonPath('data.author.name', 'Morgan Visitor');
        $messageId = $message->json('data.id');
        $this->withToken($sessionToken)->putJson("/api/v1/meeting-guest/messages/{$messageId}/reactions", [
            'kind' => 'support',
        ])->assertOk()
            ->assertJsonPath('data.reactions.0.reacted_by_current_user', true);

        $this->withToken($sessionToken)->postJson('/api/v1/meeting-guest/outcomes', [
            'kind' => MeetingOutcomeKind::Decision->value,
            'body' => 'Use meeting-only guest sessions.',
        ])->assertCreated()
            ->assertJsonPath('data.author.id', $guestId);
        $this->withToken($sessionToken)->postJson('/api/v1/meeting-guest/outcomes', [
            'kind' => MeetingOutcomeKind::Action->value,
            'body' => 'Attempt an action.',
        ])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->withToken($sessionToken)->postJson('/api/v1/meeting-guest/reactions', [
            'kind' => 'celebrate',
        ])->assertOk()->assertJsonPath('data.accepted', true);

        Event::assertDispatched(MeetingRoomReactionSent::class, fn (MeetingRoomReactionSent $event): bool => $event->broadcastWith()['actor_user_id'] === $guestId
            && $event->broadcastWith()['kind'] === 'celebrate'
        );
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->actingAs($organizer, 'web')->getJson("/api/v1/meetings/{$meeting->public_id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $guestId, 'name' => 'Morgan Visitor', 'kind' => 'guest'])
            ->assertJsonPath('data.outcomes.0.author.name', 'Morgan Visitor');
        $this->actingAs($organizer, 'web')->getJson("/api/v1/meetings/{$meeting->public_id}/messages")
            ->assertOk()->assertJsonPath('data.0.author.name', 'Morgan Visitor');
    }

    public function test_link_revocation_and_regeneration_block_future_entry_but_keep_admitted_session(): void
    {
        [$meeting, $organizer] = $this->meeting();
        $oldToken = $meeting->guest_link_token;
        $admission = $this->admit($meeting, 'Already Admitted');

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/guest-link/revoke")
            ->assertOk()->assertJsonPath('data.guest_link_url', null);
        $this->postJson("/api/v1/meeting-guests/{$meeting->public_id}/inspect", ['token' => $oldToken])->assertNotFound();
        $this->withToken($admission['token'])->getJson('/api/v1/meeting-guest/session')->assertOk();

        $regenerated = $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/guest-link/regenerate")
            ->assertOk();
        parse_str((string) parse_url($regenerated->json('data.guest_link_url'), PHP_URL_FRAGMENT), $fragment);
        $newToken = $fragment['token'];
        $this->assertNotSame($oldToken, $newToken);
        $this->postJson("/api/v1/meeting-guests/{$meeting->public_id}/inspect", ['token' => $oldToken])->assertNotFound();
        $this->postJson("/api/v1/meeting-guests/{$meeting->public_id}/inspect", ['token' => $newToken])->assertOk();
    }

    public function test_scheduled_terminal_expired_and_capped_admission_fail_safely(): void
    {
        [$scheduled] = $this->meeting(false);
        $this->postJson("/api/v1/meeting-guests/{$scheduled->public_id}/inspect", ['token' => $scheduled->guest_link_token])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->postJson("/api/v1/meeting-guests/{$scheduled->public_id}/admit", [
            'token' => $scheduled->guest_link_token,
            'display_name' => 'Waiting Guest',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('meeting');

        [$meeting, $organizer] = $this->meeting();
        for ($index = 1; $index <= MeetingGuestAccess::GUEST_CAP; $index++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$index}"])
                ->postJson("/api/v1/meeting-guests/{$meeting->public_id}/admit", [
                    'token' => $meeting->guest_link_token,
                    'display_name' => "Guest {$index}",
                    'idempotency_key' => (string) Str::uuid(),
                ])->assertCreated();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.250'])
            ->postJson("/api/v1/meeting-guests/{$meeting->public_id}/admit", [
                'token' => $meeting->guest_link_token,
                'display_name' => 'Guest Twenty Six',
                'idempotency_key' => (string) Str::uuid(),
            ])->assertUnprocessable()->assertJsonValidationErrors('meeting');

        $this->actingAs($organizer)->postJson("/api/v1/meetings/{$meeting->public_id}/end")->assertOk();
        $this->postJson("/api/v1/meeting-guests/{$meeting->public_id}/inspect", ['token' => $meeting->guest_link_token])->assertNotFound();

        [$expiring] = $this->meeting();
        CarbonImmutable::setTestNow(now()->addMinutes(31));
        $this->postJson("/api/v1/meeting-guests/{$expiring->public_id}/inspect", ['token' => $expiring->guest_link_token])->assertNotFound();
    }

    public function test_inspection_and_admission_have_separate_network_and_token_budgets(): void
    {
        [$meeting] = $this->meeting();

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.1'])
                ->postJson("/api/v1/meeting-guests/{$meeting->public_id}/inspect", [
                    'token' => $meeting->guest_link_token,
                ])->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.1'])
            ->postJson("/api/v1/meeting-guests/{$meeting->public_id}/inspect", [
                'token' => $meeting->guest_link_token,
            ])->assertTooManyRequests();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.2'])
                ->postJson("/api/v1/meeting-guests/{$meeting->public_id}/admit", [
                    'token' => $meeting->guest_link_token,
                    'display_name' => "Throttled Guest {$attempt}",
                    'idempotency_key' => (string) Str::uuid(),
                ])->assertCreated();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.2'])
            ->postJson("/api/v1/meeting-guests/{$meeting->public_id}/admit", [
                'token' => $meeting->guest_link_token,
                'display_name' => 'Throttled Guest Eleven',
                'idempotency_key' => (string) Str::uuid(),
            ])->assertTooManyRequests();
    }

    public function test_rotating_invalid_tokens_cannot_bypass_the_inspection_ip_budget(): void
    {
        [$meeting] = $this->meeting();

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.3'])
                ->postJson("/api/v1/meeting-guests/{$meeting->public_id}/inspect", [
                    'token' => Str::random(64),
                ])->assertNotFound();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.3'])
            ->postJson("/api/v1/meeting-guests/{$meeting->public_id}/inspect", [
                'token' => Str::random(64),
            ])->assertTooManyRequests();
    }

    public function test_guest_presence_authorization_uses_the_meeting_only_guard(): void
    {
        [$meeting, $organizer] = $this->meeting();
        [$other] = $this->meeting();
        $this->assertNotSame($meeting->public_id, $other->public_id);
        $admission = $this->admit($meeting, 'Presence Guest');

        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'testkey',
            'broadcasting.connections.pusher.secret' => 'testsecret',
            'broadcasting.connections.pusher.app_id' => 'testapp',
        ]);
        Broadcast::purge('pusher');
        require base_path('routes/channels.php');

        $authorized = $this->actingAs($organizer)->withToken($admission['token'])->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-meetings.{$meeting->public_id}",
        ])->assertOk();
        $channelData = json_decode((string) $authorized->json('channel_data'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($admission['broadcast_id'], $channelData['user_id']);
        $this->assertSame($admission['participant_id'], $channelData['user_info']['id']);

        $this->app['auth']->forgetGuards();
        $wrongMeeting = $this->withToken($admission['token'])->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-meetings.{$other->public_id}",
        ]);
        $wrongMeeting->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($organizer)->withToken('invalid-session-token')->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-meetings.{$meeting->public_id}",
        ])->assertForbidden();
    }

    /** @return array{Meeting, User} */
    private function meeting(bool $live = true): array
    {
        $organization = Organization::query()->where('kind', 'operating')->first()
            ?? Organization::factory()->operating()->create();
        $organizer = $this->member($organization);
        $meeting = app(MeetingService::class)->create(
            $organization,
            $organizer,
            [],
            'Guest admission review',
            $live ? CarbonImmutable::now() : CarbonImmutable::now()->addHour(),
            30,
            'Make guest access narrow and usable.',
            [],
            [],
            $live,
        );

        return [$meeting, $organizer];
    }

    /** @return array{token: string, participant_id: string, broadcast_id: string} */
    private function admit(Meeting $meeting, string $name): array
    {
        $response = $this->postJson("/api/v1/meeting-guests/{$meeting->public_id}/admit", [
            'token' => $meeting->guest_link_token,
            'display_name' => $name,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $token = $response->json('data.session_token');
        $session = MeetingGuestSession::query()
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();

        return [
            'token' => $token,
            'participant_id' => $response->json('data.participant.id'),
            'broadcast_id' => $session->getAuthIdentifierForBroadcasting(),
        ];
    }

    private function member(Organization $organization): User
    {
        $user = User::factory()->create();
        OrganizationMembership::factory()->create([
            'organization_id' => $organization,
            'user_id' => $user,
        ]);
        app(OrganizationAuthorization::class)->assign($user, $organization, OrganizationRole::InternalMember);

        return $user;
    }
}
