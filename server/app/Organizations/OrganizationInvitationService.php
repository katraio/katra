<?php

namespace App\Organizations;

use App\Auth\OrganizationAuthorization;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationAbility;
use App\Enums\OrganizationInvitationEventKind;
use App\Enums\OrganizationKind;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

final class OrganizationInvitationService
{
    public function __construct(
        private readonly OrganizationAuthorization $authorization,
        private readonly OrganizationInvitationDelivery $delivery,
    ) {}

    public function issue(
        Organization $organization,
        User $inviter,
        string $email,
        OrganizationRole $role,
    ): IssuedOrganizationInvitation {
        $email = Str::lower(trim($email));

        $this->authorizeIssue($inviter, $organization, $role);

        if ($role->membershipKind()->value === 'client' && $organization->kind !== OrganizationKind::Client) {
            throw ValidationException::withMessages([
                'role' => ['Client roles may only be invited to a client Organization.'],
            ]);
        }

        if ($organization->memberships()->whereHas('user', fn ($query) => $query->where('email', $email))->exists()) {
            throw ValidationException::withMessages([
                'email' => ['This account already has an Organization membership.'],
            ]);
        }

        $issued = DB::transaction(function () use ($organization, $inviter, $email, $role): IssuedOrganizationInvitation {
            $superseded = $organization->invitations()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get();

            foreach ($superseded as $pending) {
                $pending->forceFill(['revoked_at' => now()])->save();
                $pending->events()->create([
                    'kind' => OrganizationInvitationEventKind::Superseded,
                    'actor_user_id' => $inviter->getKey(),
                ]);
            }

            $token = Str::random(64);
            $invitation = $organization->invitations()->create([
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'invited_by_user_id' => $inviter->getKey(),
                'expires_at' => now()->addDays(7),
            ]);
            $invitation->events()->create([
                'kind' => $superseded->isEmpty()
                    ? OrganizationInvitationEventKind::Issued
                    : OrganizationInvitationEventKind::Reissued,
                'actor_user_id' => $inviter->getKey(),
            ]);

            $url = rtrim((string) config('app.client_url'), '/').'/accept-invitation#token='.rawurlencode($token);

            return new IssuedOrganizationInvitation($invitation, $token, $url);
        });

        $this->delivery->dispatch($issued);

        return $issued;
    }

    public function reissue(OrganizationInvitation $invitation, User $actor): IssuedOrganizationInvitation
    {
        $this->authorizeManage($invitation, $actor);

        return $this->issue(
            $invitation->organization,
            $actor,
            $invitation->email,
            $invitation->role,
        );
    }

    public function inspect(string $token): OrganizationInvitation
    {
        return $this->findByToken($token)->load('organization');
    }

    public function accept(string $token, User $user): OrganizationMembership
    {
        return DB::transaction(function () use ($token, $user): OrganizationMembership {
            $invitation = $this->findByToken($token, lock: true);

            if (! $invitation->isAcceptable()) {
                throw new GoneHttpException('This invitation is no longer available.');
            }

            if (Str::lower($user->email) !== $invitation->email) {
                throw ValidationException::withMessages([
                    'email' => ['Sign in with the email address that received this invitation.'],
                ]);
            }

            if ($invitation->organization->memberships()->where('user_id', $user->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['This account already has an Organization membership.'],
                ]);
            }

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $membership = $invitation->organization->memberships()->create([
                'user_id' => $user->getKey(),
                'kind' => $invitation->role->membershipKind(),
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
                'created_by_user_id' => $invitation->invited_by_user_id,
            ]);

            $this->authorization->assign($user, $invitation->organization, $invitation->role);

            $invitation->forceFill([
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->getKey(),
            ])->save();
            $invitation->events()->create([
                'kind' => OrganizationInvitationEventKind::Accepted,
                'actor_user_id' => $user->getKey(),
            ]);

            return $membership;
        });
    }

    public function revoke(OrganizationInvitation $invitation, User $actor): void
    {
        $this->authorizeManage($invitation, $actor);

        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => ['An accepted invitation cannot be revoked.'],
            ]);
        }

        if ($invitation->revoked_at !== null) {
            return;
        }

        DB::transaction(function () use ($invitation, $actor): void {
            $locked = OrganizationInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());

            if ($locked->revoked_at !== null) {
                return;
            }

            $locked->forceFill(['revoked_at' => now()])->save();
            $locked->events()->create([
                'kind' => OrganizationInvitationEventKind::Revoked,
                'actor_user_id' => $actor->getKey(),
            ]);
        });
    }

    /** @return list<OrganizationRole> */
    public function allowedRoles(User $user, Organization $organization): array
    {
        return array_values(array_filter(
            OrganizationRole::cases(),
            function (OrganizationRole $role) use ($user, $organization): bool {
                if ($role->membershipKind()->value === 'client' && $organization->kind !== OrganizationKind::Client) {
                    return false;
                }

                if ($user->isGlobalAdministrator()) {
                    return true;
                }

                $ability = match ($role) {
                    OrganizationRole::Administrator => null,
                    OrganizationRole::InternalMember => OrganizationAbility::InviteInternalMembers,
                    OrganizationRole::ClientAdministrator => OrganizationAbility::InviteClientAdministrators,
                    OrganizationRole::ClientMember => OrganizationAbility::InviteClientMembers,
                };

                return $ability !== null
                    && $this->authorization->allows($user, $organization, $ability->value);
            },
        ));
    }

    private function authorizeIssue(User $user, Organization $organization, OrganizationRole $role): void
    {
        if (! in_array($role, $this->allowedRoles($user, $organization), true)) {
            throw new AuthorizationException;
        }
    }

    private function authorizeManage(OrganizationInvitation $invitation, User $actor): void
    {
        if (! $this->authorization->allows(
            $actor,
            $invitation->organization,
            OrganizationAbility::ManageMembers->value,
        ) && $actor->getKey() !== $invitation->invited_by_user_id) {
            throw new AuthorizationException;
        }
    }

    private function findByToken(string $token, bool $lock = false): OrganizationInvitation
    {
        $query = OrganizationInvitation::query()
            ->with('organization')
            ->where('token_hash', hash('sha256', $token));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
