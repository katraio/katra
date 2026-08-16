<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Enums\OrganizationRole;
use App\Meetings\MeetingService;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class MeetingParticipantRemovalHttpTest extends TestCase
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

    public function test_organizer_removes_and_restores_a_katra_participant_without_erasing_history(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->member($organization);
        $participant = $this->member($organization);
        $meeting = $this->meeting($organization, $organizer, [$participant]);
        $record = $meeting->participants()->where('user_id', $participant->getKey())->firstOrFail();
        $record->forceFill(['joined_at' => now()])->save();

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/participants/{$record->public_id}/remove")
            ->assertOk()
            ->assertJsonCount(1, 'data.participants');

        $this->assertDatabaseHas('meeting_participants', [
            'id' => $record->getKey(),
            'user_id' => $participant->getKey(),
            'removed_by_user_id' => $organizer->getKey(),
        ]);
        $this->assertNotNull($record->fresh()->left_at);
        $this->assertDatabaseHas('meeting_participant_events', [
            'meeting_participant_id' => $record->getKey(),
            'kind' => 'removed',
            'actor_user_id' => $organizer->getKey(),
        ]);
        $this->actingAs($participant)->getJson('/api/v1/meetings')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($participant)->getJson("/api/v1/meetings/{$meeting->public_id}")->assertNotFound();

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/participants", [
                'participant_ids' => [$participant->public_id],
            ])
            ->assertOk()
            ->assertJsonFragment(['id' => $participant->public_id, 'can_remove' => true]);

        $this->assertDatabaseCount('meeting_participants', 2);
        $this->assertNull($record->fresh()->removed_at);
        $this->assertDatabaseHas('meeting_participant_events', [
            'meeting_participant_id' => $record->getKey(),
            'kind' => 'restored',
            'actor_user_id' => $organizer->getKey(),
        ]);
    }

    public function test_copied_link_guest_session_is_revoked_but_the_shared_link_cannot_personally_block_reentry(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->member($organization);
        $meeting = $this->meeting($organization, $organizer);
        $admission = $this->postJson("/api/v1/meeting-guests/{$meeting->public_id}/admit", [
            'token' => $meeting->guest_link_token,
            'display_name' => 'Copied Link Guest',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $participantId = $admission->json('data.participant.id');
        $sessionToken = $admission->json('data.session_token');

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/participants/{$participantId}/remove")
            ->assertOk()
            ->assertJsonMissing(['id' => $participantId]);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withToken($sessionToken)->getJson('/api/v1/meeting-guest/session')->assertUnauthorized();

        $blockedAttempt = $this->postJson("/api/v1/meeting-guests/{$meeting->public_id}/admit", [
            'token' => $meeting->guest_link_token,
            'display_name' => 'Second Link Guest',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $secondParticipantId = $blockedAttempt->json('data.participant.id');
        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/participants/{$secondParticipantId}/remove", [
                'block_reentry' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('block_reentry');
    }

    public function test_email_guest_can_be_removed_blocked_and_restored_by_a_rotated_invitation(): void
    {
        Queue::fake();
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->member($organization);
        $meeting = $this->meeting($organization, $organizer, [], ['guest@example.com']);
        $invitation = $meeting->invitations()->firstOrFail();
        $oldInvitationToken = $invitation->token;
        $admission = $this->postJson("/api/v1/meeting-invitations/{$invitation->public_id}/admit", [
            'token' => $oldInvitationToken,
            'display_name' => 'Email Guest',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $participantId = $admission->json('data.participant.id');
        $participantKey = MeetingParticipant::query()->where('public_id', $participantId)->value('id');

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/participants/{$participantId}/remove", [
                'block_reentry' => true,
            ])
            ->assertOk()
            ->assertJsonFragment(['id' => $invitation->public_id, 'status' => 'revoked', 'url' => null]);
        $this->postJson("/api/v1/meeting-invitations/{$invitation->public_id}/inspect", [
            'token' => $oldInvitationToken,
        ])->assertNotFound();
        $this->withToken($admission->json('data.session_token'))
            ->getJson('/api/v1/meeting-guest/session')
            ->assertUnauthorized();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/guest-invitations/{$invitation->public_id}/resend")
            ->assertOk();
        $fresh = $invitation->fresh();
        $this->assertNotSame($oldInvitationToken, $fresh->token);
        $restored = $this->postJson("/api/v1/meeting-invitations/{$invitation->public_id}/admit", [
            'token' => $fresh->token,
            'display_name' => 'Email Guest Restored',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $this->assertSame($participantId, $restored->json('data.participant.id'));
        $this->assertSame($participantKey, MeetingParticipant::query()->where('public_id', $participantId)->value('id'));
        $this->assertDatabaseHas('meeting_participant_events', [
            'meeting_participant_id' => $participantKey,
            'kind' => 'restored',
        ]);
    }

    public function test_nonorganizer_organizer_self_and_terminal_removal_fail_closed(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->member($organization);
        $participant = $this->member($organization);
        $meeting = $this->meeting($organization, $organizer, [$participant]);
        $organizerRecord = $meeting->participants()->where('user_id', $organizer->getKey())->firstOrFail();
        $participantRecord = $meeting->participants()->where('user_id', $participant->getKey())->firstOrFail();

        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/participants/{$organizerRecord->public_id}/remove")
            ->assertForbidden();
        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/participants/{$organizerRecord->public_id}/remove")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('participant');
        $this->actingAs($organizer)->postJson("/api/v1/meetings/{$meeting->public_id}/end")->assertOk();
        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meeting->public_id}/participants/{$participantRecord->public_id}/remove")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meeting');
    }

    /** @param iterable<User> $participants */
    private function meeting(
        Organization $organization,
        User $organizer,
        iterable $participants = [],
        array $guestEmails = [],
    ): Meeting {
        return app(MeetingService::class)->create(
            $organization,
            $organizer,
            $participants,
            'Participant access review',
            CarbonImmutable::now(),
            30,
            null,
            $guestEmails,
            [],
            true,
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
