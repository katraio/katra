<?php

namespace App\Organizations;

use App\Auth\OrganizationAuthorization;
use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationAbility;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class MemberAdministration
{
    public function __construct(
        private readonly OrganizationAuthorization $authorization,
        private readonly OrganizationInvitationService $invitations,
    ) {}

    /** @return EloquentCollection<int, Organization> */
    public function scopes(User $user): EloquentCollection
    {
        $organizations = $user->isGlobalAdministrator()
            ? Organization::query()->orderBy('name')->get()
            : Organization::query()
                ->whereHas('memberships', fn (Builder $query): Builder => $query
                    ->where('user_id', $user->getKey())
                    ->where('status', MembershipStatus::Active->value))
                ->orderBy('name')
                ->get();

        return $organizations
            ->filter(fn (Organization $organization): bool => $this->canAdminister($user, $organization))
            ->values();
    }

    public function resolveScope(User $user, string $publicId): Organization
    {
        $organization = Organization::query()->where('public_id', $publicId)->firstOrFail();

        if (! $this->canAdminister($user, $organization)) {
            throw (new ModelNotFoundException)->setModel(Organization::class, [$publicId]);
        }

        return $organization;
    }

    public function canAdminister(User $user, Organization $organization): bool
    {
        return $this->canManageMembers($user, $organization)
            || $this->invitations->allowedRoles($user, $organization) !== [];
    }

    public function canManageMembers(User $user, Organization $organization): bool
    {
        return $this->authorization->allows(
            $user,
            $organization,
            OrganizationAbility::ManageMembers->value,
        );
    }

    /** @return list<OrganizationRole> */
    public function allowedInvitationRoles(User $user, Organization $organization): array
    {
        return $this->invitations->allowedRoles($user, $organization);
    }

    /** @return Builder<OrganizationMembership> */
    public function members(User $user, Organization $organization): Builder
    {
        $this->ensureScope($user, $organization);

        return $organization->memberships()
            ->getQuery()
            ->when(
                ! $this->canManageMembers($user, $organization),
                fn (Builder $query): Builder => $query->where('kind', MembershipKind::Client->value),
            )
            ->with('user')
            ->join('users', 'users.id', '=', 'organization_memberships.user_id')
            ->orderBy('users.first_name')
            ->orderBy('users.last_name')
            ->orderBy('organization_memberships.id')
            ->select('organization_memberships.*');
    }

    /** @return Builder<OrganizationInvitation> */
    public function invitationHistory(User $user, Organization $organization): Builder
    {
        $this->ensureScope($user, $organization);

        return $organization->invitations()
            ->getQuery()
            ->when(
                ! $this->canManageMembers($user, $organization),
                fn (Builder $query): Builder => $query
                    ->where('invited_by_user_id', $user->getKey())
                    ->where('role', OrganizationRole::ClientMember->value),
            )
            ->with(['invitedBy', 'acceptedBy'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function roleFor(OrganizationMembership $membership, Organization $organization): ?OrganizationRole
    {
        return $this->authorization->role($membership->user, $organization);
    }

    public function canReissue(User $user, OrganizationInvitation $invitation): bool
    {
        if ($invitation->accepted_at !== null) {
            return false;
        }

        return $this->canManageInvitation($user, $invitation)
            && in_array(
                $invitation->role,
                $this->allowedInvitationRoles($user, $invitation->organization),
                true,
            );
    }

    public function canRevoke(User $user, OrganizationInvitation $invitation): bool
    {
        return $invitation->lifecycleStatus() === 'pending'
            && $this->canManageInvitation($user, $invitation);
    }

    private function canManageInvitation(User $user, OrganizationInvitation $invitation): bool
    {
        return $this->canManageMembers($user, $invitation->organization)
            || $invitation->invited_by_user_id === $user->getKey();
    }

    private function ensureScope(User $user, Organization $organization): void
    {
        if (! $this->canAdminister($user, $organization)) {
            throw (new ModelNotFoundException)->setModel(Organization::class, [$organization->public_id]);
        }
    }
}
