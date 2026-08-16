<?php

namespace App\Meetings;

use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationKind;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class MeetingAccess
{
    /** @return Builder<Meeting> */
    public function visibleTo(User $user): Builder
    {
        $meetings = Meeting::query()->whereHas(
            'participants',
            fn (Builder $participants): Builder => $participants
                ->where('user_id', $user->getKey())
                ->whereNull('removed_at'),
        );

        if ($user->isGlobalAdministrator()) {
            return $meetings;
        }

        $isOperatingInternal = $user->organizationMemberships()
            ->where('kind', MembershipKind::Internal->value)
            ->where('status', MembershipStatus::Active->value)
            ->whereHas(
                'organization',
                fn (Builder $organization): Builder => $organization
                    ->where('kind', OrganizationKind::Operating->value),
            )
            ->exists();

        return $meetings->whereHas('organization', function (Builder $organization) use ($user, $isOperatingInternal): void {
            $organization->where(function (Builder $eligible) use ($user, $isOperatingInternal): void {
                $clientMembership = fn (Builder $client): Builder => $client
                    ->where('kind', OrganizationKind::Client->value)
                    ->whereHas(
                        'memberships',
                        fn (Builder $membership): Builder => $membership
                            ->where('user_id', $user->getKey())
                            ->where('kind', MembershipKind::Client->value)
                            ->where('status', MembershipStatus::Active->value),
                    );

                if (! $isOperatingInternal) {
                    $eligible->where($clientMembership);

                    return;
                }

                $eligible
                    ->whereHas(
                        'memberships',
                        fn (Builder $membership): Builder => $membership
                            ->where('user_id', $user->getKey())
                            ->where('kind', MembershipKind::Internal->value)
                            ->where('status', MembershipStatus::Active->value),
                    )
                    ->orWhere($clientMembership);
            });
        });
    }

    public function findVisible(User $user, string $publicId): Meeting
    {
        return $this->visibleTo($user)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }
}
