<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class MeetingOutcomeHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bouncer::scope()->remove();
        $this->seed(AuthorizationSeeder::class);
        CarbonImmutable::setTestNow('2026-08-10T10:00:00-04:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Bouncer::scope()->remove();

        parent::tearDown();
    }

    public function test_guest_cannot_use_meeting_outcomes(): void
    {
        $this->getJson('/api/v1/meetings/01J00000000000000000000000/outcomes')
            ->assertUnauthorized();
    }

    public function test_participants_append_private_outcomes_and_actions_enter_normal_attention(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->member($organization);
        $participant = $this->member($organization);
        $outsider = $this->member($organization, OrganizationRole::Administrator);
        $meetingId = $this->liveMeeting($organization, $organizer, $participant);

        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meetingId}/outcomes", [
                'kind' => 'note',
                'body' => 'Keep the implementation behind the existing Notes surface.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sequence', 1)
            ->assertJsonPath('data.kind', 'note')
            ->assertJsonPath('data.author.id', $participant->public_id)
            ->assertJsonPath('data.assignee', null);

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meetingId}/outcomes", [
                'kind' => 'decision',
                'body' => 'Meeting follow-up belongs in the normal Inbox.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sequence', 2)
            ->assertJsonPath('data.kind', 'decision');

        $action = $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meetingId}/outcomes", [
                'kind' => 'action',
                'body' => 'Verify the durable meeting summary.',
                'assignee_user_id' => $participant->public_id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.sequence', 3)
            ->assertJsonPath('data.assignee.id', $participant->public_id)
            ->assertJsonPath('data.completed_at', null);

        $this->assertDatabaseHas('meeting_outcomes', [
            'public_id' => $action->json('data.id'),
            'kind' => 'action',
            'assignee_user_id' => $participant->getKey(),
        ]);
        $this->assertDatabaseHas('attention_items', [
            'user_id' => $participant->getKey(),
            'kind' => 'meeting-action',
            'state' => 'open',
        ]);

        $attention = $this->actingAs($participant)
            ->getJson('/api/v1/attention')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'meeting-action')
            ->assertJsonPath('data.0.destination.type', 'meeting')
            ->assertJsonPath('data.0.destination.meeting_id', $meetingId)
            ->assertJsonPath('data.0.context', 'Verify the durable meeting summary.');

        $attentionId = $attention->json('data.0.id');
        CarbonImmutable::setTestNow('2026-08-10T10:05:00-04:00');
        $this->actingAs($participant)
            ->postJson("/api/v1/attention/{$attentionId}/resolve")
            ->assertOk()
            ->assertJsonPath('data.state', 'resolved');

        $this->actingAs($participant)
            ->getJson("/api/v1/meetings/{$meetingId}/outcomes")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.completed_at', '2026-08-10T14:05:00.000000Z');

        $this->actingAs($outsider)
            ->getJson("/api/v1/meetings/{$meetingId}/outcomes")
            ->assertNotFound();
    }

    public function test_actions_require_a_recorded_participant_and_terminal_meetings_are_read_only(): void
    {
        $organization = Organization::factory()->operating()->create();
        $organizer = $this->member($organization);
        $participant = $this->member($organization);
        $outsider = $this->member($organization);
        $meetingId = $this->liveMeeting($organization, $organizer, $participant);

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meetingId}/outcomes", [
                'kind' => 'action',
                'body' => 'This assignment must fail closed.',
                'assignee_user_id' => $outsider->public_id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignee_user_id');

        $this->actingAs($organizer)
            ->postJson("/api/v1/meetings/{$meetingId}/end")
            ->assertOk();

        $this->actingAs($participant)
            ->postJson("/api/v1/meetings/{$meetingId}/outcomes", [
                'kind' => 'note',
                'body' => 'This meeting is already read-only.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meeting');

        $this->assertDatabaseCount('meeting_outcomes', 0);
        $this->assertDatabaseCount('attention_items', 0);
    }

    private function liveMeeting(Organization $organization, User $organizer, User $participant): string
    {
        return $this->actingAs($organizer)
            ->postJson("/api/v1/organizations/{$organization->public_id}/meetings/instant", [
                'title' => 'Durable outcomes review',
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
