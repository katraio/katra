<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Enums\OrganizationInvitationEventKind;
use App\Enums\OrganizationRole;
use App\Enums\SystemRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Organizations\OrganizationInvitationService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class OrganizationInvitationHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bouncer::scope()->remove();
        $this->seed(AuthorizationSeeder::class);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Bouncer::scope()->remove();

        parent::tearDown();
    }

    public function test_global_administrator_can_issue_and_revoke_an_invitation(): void
    {
        $administrator = User::factory()->create();
        $administrator->assign(SystemRole::GlobalAdministrator->value);
        $organization = Organization::factory()->create();

        $response = $this->actingAs($administrator)->postJson(
            "/api/v1/organizations/{$organization->public_id}/invitations",
            [
                'email' => '  CLIENT@EXAMPLE.COM ',
                'role' => OrganizationRole::ClientAdministrator->value,
            ],
        )->assertCreated()
            ->assertJsonPath('data.organization_id', $organization->public_id)
            ->assertJsonPath('data.email', 'client@example.com')
            ->assertJsonPath('data.role', OrganizationRole::ClientAdministrator->value);

        $invitation = OrganizationInvitation::query()->sole();

        $this->assertNull($invitation->last_sent_at);
        $this->assertSame('copy-link-only', $invitation->last_delivery_status->value);
        $this->assertNotNull($invitation->last_delivery_at);
        $this->assertStringStartsWith(
            config('app.client_url').'/accept-invitation#token=',
            $response->json('data.acceptance_url'),
        );
        Notification::assertNothingSent();

        $this->actingAs($administrator)
            ->deleteJson(
                "/api/v1/organizations/{$organization->public_id}/invitations/{$invitation->public_id}",
            )
            ->assertNoContent();

        $this->assertNotNull($invitation->fresh()->revoked_at);
    }

    public function test_guest_can_inspect_and_accept_a_new_account_invitation(): void
    {
        $administrator = User::factory()->create();
        $administrator->assign(SystemRole::GlobalAdministrator->value);
        $organization = Organization::factory()->create(['name' => 'Northstar Goods']);
        $issued = app(OrganizationInvitationService::class)->issue(
            $organization,
            $administrator,
            'ada@example.com',
            OrganizationRole::ClientMember,
        );

        $this->postWithCsrf('/auth/invitations/inspect', ['token' => $issued->token])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'organization_name' => 'Northstar Goods',
                    'email' => 'ada@example.com',
                    'role' => OrganizationRole::ClientMember->value,
                    'expires_at' => $issued->invitation->expires_at->toISOString(),
                    'existing_account' => false,
                ],
            ]);

        $this->postWithCsrf('/auth/invitations/accept', [
            'token' => $issued->token,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertCreated()
            ->assertJsonPath('data.organization_id', $organization->public_id)
            ->assertJsonPath('data.role', OrganizationRole::ClientMember->value);

        $user = User::query()->where('email', 'ada@example.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'kind' => 'client',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('organization_invitation_events', [
            'organization_invitation_id' => $issued->invitation->getKey(),
            'kind' => OrganizationInvitationEventKind::Accepted->value,
        ]);
    }

    public function test_delivering_mail_transport_reports_queued_and_dispatches_the_encrypted_notification(): void
    {
        config()->set('mail.default', 'smtp');

        $administrator = User::factory()->create();
        $administrator->assign(SystemRole::GlobalAdministrator->value);
        $organization = Organization::factory()->create();

        $response = $this->actingAs($administrator)->postJson(
            "/api/v1/organizations/{$organization->public_id}/invitations",
            [
                'email' => 'mail-ready@example.com',
                'role' => OrganizationRole::InternalMember->value,
            ],
        )->assertCreated()
            ->assertJsonPath('data.delivery_status', 'queued')
            ->assertJsonPath('data.email_delivery_attempted_at', null);

        $invitation = OrganizationInvitation::query()->sole();

        $this->assertSame('queued', $invitation->last_delivery_status->value);
        $this->assertNotNull($response->json('data.last_delivery_at'));
        $this->assertDatabaseHas('organization_invitation_events', [
            'organization_invitation_id' => $invitation->getKey(),
            'kind' => OrganizationInvitationEventKind::DeliveryQueued->value,
        ]);
        Notification::assertSentOnDemand(OrganizationInvitationNotification::class);
    }

    public function test_existing_account_must_sign_in_and_match_the_invited_email(): void
    {
        $administrator = User::factory()->create();
        $administrator->assign(SystemRole::GlobalAdministrator->value);
        $organization = Organization::factory()->create();
        $invitedUser = User::factory()->create(['email' => 'invitee@example.com']);
        $otherUser = User::factory()->create(['email' => 'other@example.com']);
        $issued = app(OrganizationInvitationService::class)->issue(
            $organization,
            $administrator,
            $invitedUser->email,
            OrganizationRole::InternalMember,
        );

        $this->postWithCsrf('/auth/invitations/inspect', ['token' => $issued->token])
            ->assertOk()
            ->assertJsonPath('data.existing_account', true);

        $this->postWithCsrf('/auth/invitations/accept', ['token' => $issued->token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->actingAs($otherUser);
        $this->postWithCsrf('/auth/invitations/accept', ['token' => $issued->token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->actingAs($invitedUser);
        $this->postWithCsrf('/auth/invitations/accept', ['token' => $issued->token])
            ->assertOk();
        $this->assertNotNull($invitedUser->fresh()->email_verified_at);
    }

    public function test_client_administrator_can_revoke_only_an_invitation_they_issued(): void
    {
        $organization = Organization::factory()->create();
        $clientAdministrator = User::factory()->create();
        OrganizationMembership::factory()->client()->create([
            'organization_id' => $organization,
            'user_id' => $clientAdministrator,
        ]);
        app(OrganizationAuthorization::class)->assign(
            $clientAdministrator,
            $organization,
            OrganizationRole::ClientAdministrator,
        );

        $ownInvitation = app(OrganizationInvitationService::class)->issue(
            $organization,
            $clientAdministrator,
            'client@example.com',
            OrganizationRole::ClientMember,
        );
        $otherInvitation = OrganizationInvitation::factory()->create([
            'organization_id' => $organization,
            'invited_by_user_id' => User::factory(),
        ]);

        $this->actingAs($clientAdministrator)
            ->deleteJson(
                "/api/v1/organizations/{$organization->public_id}/invitations/{$ownInvitation->invitation->public_id}",
            )
            ->assertNoContent();

        $this->actingAs($clientAdministrator)
            ->deleteJson(
                "/api/v1/organizations/{$organization->public_id}/invitations/{$otherInvitation->public_id}",
            )
            ->assertForbidden();
    }

    /** @param array<string, mixed> $data */
    private function postWithCsrf(string $uri, array $data = []): TestResponse
    {
        $token = 'katra-invitation-csrf-token';

        return $this->withSession(['_token' => $token])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => $token]);
    }
}
