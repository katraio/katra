<?php

namespace App\Meetings;

use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationKind;
use App\Models\Organization;
use App\Models\User;

final class MeetingParticipantEligibility
{
    public function allows(User $user, Organization $organization): bool
    {
        if ($this->isInternalAuthorizedForOrganization($user, $organization)) {
            return true;
        }

        return $organization->kind === OrganizationKind::Client
            && $this->hasActiveMembership($user, $organization, MembershipKind::Client);
    }

    private function isInternalAuthorizedForOrganization(User $user, Organization $organization): bool
    {
        if (! $this->isOperatingInternal($user)) {
            return false;
        }

        return $user->isGlobalAdministrator()
            || $this->hasActiveMembership($user, $organization, MembershipKind::Internal);
    }

    private function isOperatingInternal(User $user): bool
    {
        if ($user->isGlobalAdministrator()) {
            return true;
        }

        return $user->organizationMemberships()
            ->where('kind', MembershipKind::Internal->value)
            ->where('status', MembershipStatus::Active->value)
            ->whereHas(
                'organization',
                fn ($query) => $query->where('kind', OrganizationKind::Operating->value),
            )
            ->exists();
    }

    private function hasActiveMembership(
        User $user,
        Organization $organization,
        MembershipKind $kind,
    ): bool {
        return $user->organizationMemberships()
            ->where('organization_id', $organization->getKey())
            ->where('kind', $kind->value)
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }
}
