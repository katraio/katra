<?php

namespace App\Conversations;

use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationKind;
use App\Models\DirectMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class DirectMessageAccess
{
    /** @return Builder<DirectMessage> */
    public function visibleTo(User $user): Builder
    {
        $globalAdministrator = $user->isGlobalAdministrator();
        $internal = $this->isOperatingInternal($user);
        $internalOrganizationIds = $internal && ! $globalAdministrator
            ? $user->organizationMemberships()
                ->where('kind', MembershipKind::Internal->value)
                ->where('status', MembershipStatus::Active->value)
                ->pluck('organization_id')
            : collect();
        $clientOrganizationIds = $user->organizationMemberships()
            ->where('kind', MembershipKind::Client->value)
            ->where('status', MembershipStatus::Active->value)
            ->whereHas(
                'organization',
                fn (Builder $organization): Builder => $organization
                    ->where('kind', OrganizationKind::Client->value),
            )
            ->pluck('organization_id');

        return DirectMessage::query()
            ->with(['conversation.liveMeeting.organizer', 'organization', 'participants'])
            ->whereHas(
                'participantRecords',
                fn (Builder $participant): Builder => $participant
                    ->where('user_id', $user->getKey()),
            )
            ->when(
                ! $globalAdministrator,
                function (Builder $directMessages) use ($internalOrganizationIds, $clientOrganizationIds): void {
                    $directMessages->where(
                        function (Builder $authorized) use (
                            $internalOrganizationIds,
                            $clientOrganizationIds,
                        ): void {
                            if ($internalOrganizationIds->isNotEmpty()) {
                                $authorized->whereIn('organization_id', $internalOrganizationIds);
                            }

                            if ($clientOrganizationIds->isNotEmpty()) {
                                $method = $internalOrganizationIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                                $authorized->{$method}('organization_id', $clientOrganizationIds);
                            }

                            if ($internalOrganizationIds->isEmpty() && $clientOrganizationIds->isEmpty()) {
                                $authorized->whereRaw('1 = 0');
                            }
                        },
                    );
                },
            );
    }

    public function resolveVisible(User $user, string $publicId): DirectMessage
    {
        return $this->visibleTo($user)
            ->whereHas(
                'conversation',
                fn (Builder $conversation): Builder => $conversation->where('public_id', $publicId),
            )
            ->firstOrFail();
    }

    public function canRead(User $user, DirectMessage $directMessage): bool
    {
        return $this->visibleTo($user)->whereKey($directMessage->getKey())->exists();
    }

    public function isParticipant(DirectMessage $directMessage, User $user): bool
    {
        return $directMessage->participantRecords()
            ->where('user_id', $user->getKey())
            ->exists();
    }

    public function isOperatingInternal(User $user): bool
    {
        if ($user->isGlobalAdministrator()) {
            return true;
        }

        return $user->organizationMemberships()
            ->where('kind', MembershipKind::Internal->value)
            ->where('status', MembershipStatus::Active->value)
            ->whereHas(
                'organization',
                fn (Builder $organization): Builder => $organization
                    ->where('kind', OrganizationKind::Operating->value),
            )
            ->exists();
    }

    public function isInternalParticipant(DirectMessage $directMessage, User $user): bool
    {
        return $this->isParticipant($directMessage, $user) && $this->isOperatingInternal($user);
    }

    public function isClientParticipant(DirectMessage $directMessage, User $user): bool
    {
        return $this->isParticipant($directMessage, $user)
            && $user->organizationMemberships()
                ->where('organization_id', $directMessage->organization_id)
                ->where('kind', MembershipKind::Client->value)
                ->where('status', MembershipStatus::Active->value)
                ->exists();
    }
}
