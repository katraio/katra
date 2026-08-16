<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Enums\OrganizationRole;
use App\Jobs\RetireMeetingMediaRoom;
use App\Meetings\MeetingService;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Katra\LiveKit\Contracts\AccessTokenFactory;
use Katra\LiveKit\ParticipantGrant;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class MeetingMediaCredentialHttpTest extends TestCase
{
    use RefreshDatabase;

    private FakeMeetingAccessTokenFactory $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        Bouncer::scope()->remove();
        $this->seed(AuthorizationSeeder::class);
        CarbonImmutable::setTestNow('2026-08-11T09:00:00-04:00');
        config([
            'livekit.public_url' => 'ws://media.katra.test:7880',
            'livekit.join_token_ttl' => 120,
        ]);
        $this->tokens = new FakeMeetingAccessTokenFactory;
        $this->app->instance(AccessTokenFactory::class, $this->tokens);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Bouncer::scope()->remove();
        parent::tearDown();
    }

    public function test_active_katra_participant_receives_a_short_lived_exact_room_credential(): void
    {
        [$meeting, $organizer] = $this->meeting();

        $response = $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/media-credential")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.url', 'ws://media.katra.test:7880')
            ->assertJsonPath('data.token', 'participant-token-1')
            ->assertJsonPath('data.room_generation', 1)
            ->assertJsonPath('data.expires_at', '2026-08-11T13:02:00+00:00');

        $participant = $meeting->participants()->where('user_id', $organizer->getKey())->firstOrFail();
        $identity = 'mp_'.Str::lower($participant->public_id);
        $response->assertJsonPath('data.participant_identity', $identity);
        $response->assertJsonMissingPath('data.room_name');

        $fresh = $meeting->fresh();
        $this->assertSame(1, $fresh->media_room_generation);
        $this->assertMatchesRegularExpression('/^kr_[0-9a-z]{26}$/', $fresh->media_room_name);
        $this->assertSame($fresh->media_room_name, $this->tokens->grants[0]->roomName);
        $this->assertSame($identity, $this->tokens->grants[0]->participantIdentity);
        $this->assertEqualsCanonicalizing([
            ParticipantGrant::CAMERA,
            ParticipantGrant::MICROPHONE,
            ParticipantGrant::SCREEN_SHARE,
            ParticipantGrant::SCREEN_SHARE_AUDIO,
        ], $this->tokens->grants[0]->publishSources);

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/media-credential")
            ->assertOk()
            ->assertJsonPath('data.room_generation', 1);
        $this->assertSame($fresh->media_room_name, $meeting->fresh()->media_room_name);
    }

    public function test_active_guest_session_receives_only_its_meeting_media_credential(): void
    {
        [$meeting] = $this->meeting();
        $admission = $this->postJson("/api/v1/meeting-guests/{$meeting->public_id}/admit", [
            'token' => $meeting->guest_link_token,
            'display_name' => 'Media Guest',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $this->withToken($admission->json('data.session_token'))
            ->postJson('/api/v1/meeting-guest/media-credential')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.token', 'participant-token-1')
            ->assertJsonPath('data.participant_identity', 'mp_'.Str::lower($admission->json('data.participant.id')))
            ->assertJsonPath('data.room_generation', 1);

        $this->assertCount(1, $this->tokens->grants);
    }

    public function test_scheduled_terminal_removed_and_outside_participants_fail_closed(): void
    {
        [$scheduled, $organizer] = $this->meeting(false);
        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$scheduled->public_id}/media-credential")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meeting');

        $meeting = $this->createMeeting($scheduled->organization, $organizer, [], true);
        $liveOrganizer = $organizer;
        $outsider = User::factory()->create();
        $this->actingAs($outsider)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/media-credential")
            ->assertNotFound();

        $participant = $this->member($meeting->organization);
        app(MeetingService::class)->addParticipants($meeting, $liveOrganizer, [$participant]);
        $record = $meeting->participants()->where('user_id', $participant->getKey())->firstOrFail();
        $this->actingAs($liveOrganizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/participants/{$record->public_id}/remove")
            ->assertOk();
        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/media-credential")
            ->assertNotFound();

        $this->actingAs($liveOrganizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/end")
            ->assertOk();
        $this->actingAs($liveOrganizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/media-credential")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meeting');
    }

    public function test_removal_rotates_the_room_generation_and_retires_the_old_room_after_commit(): void
    {
        Queue::fake();
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->member($organization);
        $participant = $this->member($organization);
        $meeting = $this->createMeeting($organization, $organizer, [$participant], true);

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/media-credential")
            ->assertOk();
        $before = $meeting->fresh();
        $record = $meeting->participants()->where('user_id', $participant->getKey())->firstOrFail();

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/participants/{$record->public_id}/remove")
            ->assertOk();

        $after = $meeting->fresh();
        $this->assertNotSame($before->media_room_name, $after->media_room_name);
        $this->assertSame(2, $after->media_room_generation);
        Queue::assertPushed(RetireMeetingMediaRoom::class, fn (RetireMeetingMediaRoom $job): bool => $job->roomName === $before->media_room_name
            && $job->participantIdentity === 'mp_'.Str::lower($record->public_id));
    }

    public function test_ending_a_meeting_retires_its_active_media_room_after_commit(): void
    {
        Queue::fake();
        [$meeting, $organizer] = $this->meeting();
        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/media-credential")
            ->assertOk();
        $room = $meeting->fresh()->media_room_name;

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/end")
            ->assertOk();

        $this->assertNull($meeting->fresh()->media_room_name);
        Queue::assertPushed(RetireMeetingMediaRoom::class, fn (RetireMeetingMediaRoom $job): bool => $job->roomName === $room
            && $job->participantIdentity === null);
    }

    /** @return array{Meeting, User} */
    private function meeting(bool $live = true): array
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->member($organization);

        return [$this->createMeeting($organization, $organizer, [], $live), $organizer];
    }

    /** @param iterable<User> $participants */
    private function createMeeting(
        Organization $organization,
        User $organizer,
        iterable $participants,
        bool $live,
    ): Meeting {
        return app(MeetingService::class)->create(
            $organization,
            $organizer,
            $participants,
            'Live media review',
            CarbonImmutable::now(),
            30,
            null,
            [],
            [],
            $live,
        );
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

final class FakeMeetingAccessTokenFactory implements AccessTokenFactory
{
    /** @var list<ParticipantGrant> */
    public array $grants = [];

    public function participant(ParticipantGrant $grant): string
    {
        $this->grants[] = $grant;

        return 'participant-token-'.count($this->grants);
    }

    public function roomAdmin(string $roomName): string
    {
        return 'room-admin-token';
    }

    public function roomCreate(): string
    {
        return 'room-create-token';
    }
}
