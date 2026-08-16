<?php

namespace Tests\Feature;

use App\Auth\OrganizationAuthorization;
use App\Auth\OrganizationScope;
use App\Enums\OrganizationRole;
use App\Enums\SystemRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Organizations\OrganizationInvitationService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Tests\TestCase;

final class OrganizationInvitationTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationInvitationService $invitations;

    protected function setUp(): void
    {
        parent::setUp();

        Bouncer::scope()->remove();
        $this->seed(AuthorizationSeeder::class);
        $this->invitations = app(OrganizationInvitationService::class);
    }

    protected function tearDown(): void
    {
        Bouncer::scope()->remove();

        parent::tearDown();
    }

    public function test_global_administrator_issues_normalized_single_use_seven_day_invitation(): void
    {
        $globalAdministrator = User::factory()->create();
        $organization = Organization::factory()->create();
        $globalAdministrator->assign(SystemRole::GlobalAdministrator->value);

        $issued = $this->invitations->issue(
            $organization,
            $globalAdministrator,
            '  CLIENT@EXAMPLE.COM ',
            OrganizationRole::ClientAdministrator,
        );

        $this->assertSame('client@example.com', $issued->invitation->email);
        $this->assertTrue($issued->invitation->expires_at->between(
            now()->addDays(7)->subMinute(),
            now()->addDays(7)->addMinute(),
        ));
        $this->assertStringEndsWith($issued->token, $issued->acceptanceUrl);
        $this->assertNotSame($issued->token, $issued->invitation->token_hash);
        $this->assertDatabaseMissing('organization_invitations', ['token_hash' => $issued->token]);

        $replacement = $this->invitations->issue(
            $organization,
            $globalAdministrator,
            'client@example.com',
            OrganizationRole::ClientAdministrator,
        );

        $this->assertNotSame($issued->token, $replacement->token);
        $this->assertNotNull($issued->invitation->fresh()->revoked_at);
    }

    public function test_scoped_administrators_can_issue_only_their_accepted_invitation_roles(): void
    {
        $organization = Organization::factory()->create();
        $organizationAdministrator = $this->memberWithRole($organization, OrganizationRole::Administrator);
        $clientAdministrator = $this->memberWithRole($organization, OrganizationRole::ClientAdministrator);

        $clientAdminInvitation = $this->invitations->issue(
            $organization,
            $organizationAdministrator,
            'client-admin@example.com',
            OrganizationRole::ClientAdministrator,
        );
        $clientMemberInvitation = $this->invitations->issue(
            $organization,
            $clientAdministrator,
            'client-member@example.com',
            OrganizationRole::ClientMember,
        );

        $this->assertSame(OrganizationRole::ClientAdministrator, $clientAdminInvitation->invitation->role);
        $this->assertSame(OrganizationRole::ClientMember, $clientMemberInvitation->invitation->role);

        $this->expectException(AuthorizationException::class);

        $this->invitations->issue(
            $organization,
            $clientAdministrator,
            'internal@example.com',
            OrganizationRole::InternalMember,
        );
    }

    public function test_matching_existing_user_accepts_once_and_receives_scoped_membership_and_role(): void
    {
        $globalAdministrator = User::factory()->create();
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['email' => 'invitee@example.com']);
        $globalAdministrator->assign(SystemRole::GlobalAdministrator->value);

        $issued = $this->invitations->issue(
            $organization,
            $globalAdministrator,
            'invitee@example.com',
            OrganizationRole::InternalMember,
        );

        $membership = $this->invitations->accept($issued->token, $user);

        $this->assertSame('internal', $membership->kind->value);
        $this->assertSame('active', $membership->status->value);
        $this->assertNotNull($issued->invitation->fresh()->accepted_at);
        $this->assertTrue(app(OrganizationScope::class)->run(
            $organization->getKey(),
            fn (): bool => $user->fresh()->isAn(OrganizationRole::InternalMember->value),
        ));

        $this->expectException(GoneHttpException::class);

        $this->invitations->accept($issued->token, $user);
    }

    public function test_acceptance_rejects_a_different_email_address(): void
    {
        $globalAdministrator = User::factory()->create();
        $organization = Organization::factory()->create();
        $wrongUser = User::factory()->create(['email' => 'wrong@example.com']);
        $globalAdministrator->assign(SystemRole::GlobalAdministrator->value);

        $issued = $this->invitations->issue(
            $organization,
            $globalAdministrator,
            'invitee@example.com',
            OrganizationRole::InternalMember,
        );

        try {
            $this->invitations->accept($issued->token, $wrongUser);
            $this->fail('A mismatched account email accepted the invitation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        $this->assertDatabaseMissing('organization_memberships', [
            'organization_id' => $organization->getKey(),
            'user_id' => $wrongUser->getKey(),
        ]);
    }

    public function test_revoked_and_expired_invitations_fail_closed(): void
    {
        $globalAdministrator = User::factory()->create();
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['email' => 'invitee@example.com']);
        $globalAdministrator->assign(SystemRole::GlobalAdministrator->value);

        $revoked = $this->invitations->issue(
            $organization,
            $globalAdministrator,
            $user->email,
            OrganizationRole::InternalMember,
        );
        $this->invitations->revoke($revoked->invitation, $globalAdministrator);

        try {
            $this->invitations->accept($revoked->token, $user);
            $this->fail('A revoked invitation was accepted.');
        } catch (GoneHttpException) {
            $this->assertTrue(true);
        }

        $expiredToken = 'expired-token-value-that-is-long-enough-for-this-test-case-123456789';
        OrganizationInvitation::factory()->create([
            'organization_id' => $organization,
            'email' => $user->email,
            'token_hash' => hash('sha256', $expiredToken),
            'invited_by_user_id' => $globalAdministrator,
            'expires_at' => now()->subMinute(),
        ]);

        $this->expectException(GoneHttpException::class);

        $this->invitations->accept($expiredToken, $user);
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
