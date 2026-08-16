<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Enums\OrganizationRole;
use App\Meetings\MeetingService;
use App\Models\Meeting;
use App\Models\MeetingInvitation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\MeetingInvitationNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class MeetingEmailInvitationHttpTest extends TestCase
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

    public function test_scheduling_queues_a_token_safe_branded_invitation_after_commit(): void
    {
        Queue::fake();
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->member($organization);

        $response = $this->actingAs($organizer)->postJson(
            "/api/v1/organizations/{$organization->public_id}/meetings",
            [
                'title' => 'External design review',
                'starts_at' => '2026-08-10T16:00:00-04:00',
                'duration_minutes' => 30,
                'participant_ids' => [],
                'guest_emails' => [' Guest@Example.com '],
                'agenda_items' => [],
            ],
        )->assertCreated()
            ->assertJsonPath('data.guest_invitations.0.email', 'guest@example.com')
            ->assertJsonPath('data.guest_invitations.0.status', 'queued')
            ->assertJsonPath('data.guest_invitations.0.send_count', 1);

        $invitation = MeetingInvitation::query()->with('meeting')->firstOrFail();
        $rawToken = $invitation->token;
        $this->assertDatabaseHas('meeting_invitation_events', [
            'meeting_invitation_id' => $invitation->getKey(),
            'kind' => 'queued',
        ]);
        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($rawToken): bool {
            $this->assertInstanceOf(MeetingInvitationNotification::class, $job->notification);
            $this->assertStringNotContainsString($rawToken, serialize($job));

            return true;
        });

        $notification = new MeetingInvitationNotification($invitation, $invitation->token_hash);
        $mail = $notification->toMail((object) []);
        $this->assertSame('Meeting invitation: External design review', $mail->subject);
        $this->assertSame('Open meeting', $mail->actionText);
        $this->assertStringContainsString("/meeting-invitations/{$invitation->public_id}#token=", $mail->actionUrl);
        $this->assertStringContainsString($rawToken, $mail->actionUrl);
        $this->assertStringNotContainsString($response->json('data.guest_link_url'), $mail->actionUrl);
        $html = $mail->render();
        $this->assertStringContainsString('alt="Katra"', $html);
        $this->assertStringContainsString('External design review', $html);
        $this->assertStringContainsString($organization->name, $html);
        $this->assertStringContainsString('Open meeting', $html);
    }

    public function test_email_invitation_is_exactly_scoped_and_rotates_one_guest_session(): void
    {
        [$meeting, $invitation] = $this->meeting();
        [$otherMeeting, $otherInvitation] = $this->meeting();

        $this->assertNotNull($invitation->fresh()->last_sent_at);
        $this->assertDatabaseHas('meeting_invitation_events', [
            'meeting_invitation_id' => $invitation->getKey(),
            'kind' => 'sent',
        ]);

        $this->postJson("/api/v1/meeting-invitations/{$invitation->public_id}/inspect", [
            'token' => $invitation->token,
        ])->assertOk()->assertJsonPath('data.title', $meeting->title);
        $this->postJson("/api/v1/meeting-invitations/{$otherInvitation->public_id}/inspect", [
            'token' => $invitation->token,
        ])->assertNotFound()->assertExactJson(['message' => 'This meeting link is unavailable or has expired.']);

        $firstKey = (string) Str::uuid();
        $first = $this->postJson("/api/v1/meeting-invitations/{$invitation->public_id}/admit", [
            'token' => $invitation->token,
            'display_name' => 'Email Guest',
            'idempotency_key' => $firstKey,
        ])->assertCreated();
        $firstToken = $first->json('data.session_token');
        $participantId = $first->json('data.participant.id');

        $this->postJson("/api/v1/meeting-invitations/{$invitation->public_id}/admit", [
            'token' => $invitation->token,
            'display_name' => 'Email Guest',
            'idempotency_key' => $firstKey,
        ])->assertOk()->assertJsonPath('data.session_token', $firstToken);

        $second = $this->postJson("/api/v1/meeting-invitations/{$invitation->public_id}/admit", [
            'token' => $invitation->token,
            'display_name' => 'Email Guest',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()
            ->assertJsonPath('data.participant.id', $participantId);
        $this->assertNotSame($firstToken, $second->json('data.session_token'));
        $this->withToken($firstToken)->getJson('/api/v1/meeting-guest/session')->assertUnauthorized();
        $this->withToken($second->json('data.session_token'))->getJson('/api/v1/meeting-guest/session')
            ->assertOk()->assertJsonPath('data.id', $meeting->public_id);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withToken($second->json('data.session_token'))->getJson('/api/v1/meetings')->assertUnauthorized();

        $this->assertDatabaseCount('meeting_guest_sessions', 1);
        $this->assertDatabaseHas('meeting_participants', [
            'meeting_id' => $meeting->getKey(),
            'meeting_invitation_id' => $invitation->getKey(),
            'guest_admission_source' => 'email-invitation',
        ]);
        $this->assertDatabaseMissing('meeting_participants', [
            'meeting_id' => $otherMeeting->getKey(),
            'meeting_invitation_id' => $invitation->getKey(),
        ]);
    }

    public function test_organizer_can_add_resend_and_revoke_without_terminating_an_admitted_session(): void
    {
        Queue::fake();
        [$meeting, $invitation, $organizer] = $this->meeting();
        $oldToken = $invitation->token;
        $admitted = $this->postJson("/api/v1/meeting-invitations/{$invitation->public_id}/admit", [
            'token' => $oldToken,
            'display_name' => 'Persistent Guest',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $added = $this->actingAs($organizer)->postJson(
            "/api/v1/meetings/{$meeting->public_id}/guest-invitations",
            ['guest_emails' => ['second@example.com']],
        )->assertOk()->assertJsonCount(2, 'data.guest_invitations');
        $secondId = collect($added->json('data.guest_invitations'))->firstWhere('email', 'second@example.com')['id'];

        $resent = $this->actingAs($organizer)->postJson(
            "/api/v1/meetings/{$meeting->public_id}/guest-invitations/{$invitation->public_id}/resend",
        )->assertOk();
        $fresh = $invitation->fresh();
        $this->assertNotSame($oldToken, $fresh->token);
        $this->postJson("/api/v1/meeting-invitations/{$invitation->public_id}/inspect", ['token' => $oldToken])->assertNotFound();
        $this->postJson("/api/v1/meeting-invitations/{$invitation->public_id}/inspect", ['token' => $fresh->token])->assertOk();
        $this->assertSame('admitted', collect($resent->json('data.guest_invitations'))->firstWhere('id', $invitation->public_id)['status']);

        $this->actingAs($organizer)->deleteJson(
            "/api/v1/meetings/{$meeting->public_id}/guest-invitations/{$secondId}",
        )->assertOk()->assertJsonFragment(['id' => $secondId, 'status' => 'revoked', 'url' => null]);
        $this->withToken($admitted->json('data.session_token'))->getJson('/api/v1/meeting-guest/session')->assertOk();
        $this->assertDatabaseHas('meeting_invitation_events', ['meeting_invitation_id' => $invitation->getKey(), 'kind' => 'resent']);
    }

    public function test_nonorganizer_and_cross_meeting_invitation_mutation_fail_closed(): void
    {
        Queue::fake();
        [$meeting, $invitation] = $this->meeting();
        [$otherMeeting, $otherInvitation, $otherOrganizer] = $this->meeting();

        $this->actingAs($otherOrganizer)->postJson(
            "/api/v1/meetings/{$meeting->public_id}/guest-invitations",
            ['guest_emails' => ['outsider@example.com']],
        )->assertNotFound();
        $this->actingAs($otherOrganizer)->postJson(
            "/api/v1/meetings/{$otherMeeting->public_id}/guest-invitations/{$invitation->public_id}/resend",
        )->assertNotFound();
        $this->assertSame(1, $otherInvitation->fresh()->send_count);
    }

    /** @return array{Meeting, MeetingInvitation, User} */
    private function meeting(): array
    {
        $organization = Organization::query()->where('kind', 'operating')->first()
            ?? Organization::factory()->operating()->create();
        $organizer = $this->member($organization);
        $meeting = app(MeetingService::class)->create(
            $organization,
            $organizer,
            [],
            'Email invitation review',
            CarbonImmutable::now(),
            30,
            null,
            [Str::lower(fake()->unique()->safeEmail())],
            [],
            true,
        );

        return [$meeting, $meeting->invitations->firstOrFail(), $organizer];
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
