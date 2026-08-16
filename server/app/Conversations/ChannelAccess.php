<?php

namespace App\Conversations;

use App\Auth\OrganizationAuthorization;
use App\Enums\ChannelMembershipRole;
use App\Enums\ChannelVisibility;
use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationAbility;
use App\Enums\OrganizationKind;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ChannelAccess
{
    public function __construct(private readonly OrganizationAuthorization $authorization) {}

    /** @return Builder<Channel> */
    public function visibleTo(User $user): Builder
    {
        $internal = $this->isOperatingInternal($user);
        $clientOrganizationIds = $user->organizationMemberships()
            ->where('kind', MembershipKind::Client->value)
            ->where('status', MembershipStatus::Active->value)
            ->pluck('organization_id');

        return Channel::query()
            ->with(['conversation.liveMeeting.organizer', 'organization'])
            ->where(function (Builder $visible) use ($user, $internal, $clientOrganizationIds): void {
                if ($internal) {
                    $visible->where(function (Builder $public): void {
                        $public->where('visibility', ChannelVisibility::Public->value)
                            ->whereHas(
                                'organization',
                                fn (Builder $organization): Builder => $organization
                                    ->where('kind', OrganizationKind::Operating->value),
                            );
                    })->orWhere(function (Builder $private) use ($user): void {
                        $private
                            ->where('visibility', ChannelVisibility::Private->value)
                            ->whereHas(
                                'organization',
                                fn (Builder $organization): Builder => $organization
                                    ->where('kind', OrganizationKind::Operating->value),
                            )
                            ->whereHas(
                                'conversation.memberships',
                                fn (Builder $membership): Builder => $membership
                                    ->where('user_id', $user->getKey())
                                    ->whereNull('left_at')
                                    ->whereNull('removed_at'),
                            );
                    })->orWhere(function (Builder $clientTeam) use ($user): void {
                        $clientTeam
                            ->where('visibility', ChannelVisibility::ClientTeam->value)
                            ->whereHas(
                                'conversation.memberships',
                                fn (Builder $membership): Builder => $membership
                                    ->where('user_id', $user->getKey())
                                    ->whereNull('left_at')
                                    ->whereNull('removed_at'),
                            );
                    });
                }

                if ($clientOrganizationIds->isNotEmpty()) {
                    $method = $internal ? 'orWhere' : 'where';
                    $visible->{$method}(function (Builder $clientTeam) use ($clientOrganizationIds): void {
                        $clientTeam
                            ->where('visibility', ChannelVisibility::ClientTeam->value)
                            ->whereIn('organization_id', $clientOrganizationIds);
                    });
                }

                if (! $internal && $clientOrganizationIds->isEmpty()) {
                    $visible->whereRaw('1 = 0');
                }
            });
    }

    public function canRead(User $user, Channel $channel): bool
    {
        return $this->visibleTo($user)->whereKey($channel->getKey())->exists();
    }

    public function canManage(User $user, Channel $channel): bool
    {
        if (
            in_array($channel->visibility, [ChannelVisibility::Public, ChannelVisibility::Private], true)
            && (
                $channel->organization->kind !== OrganizationKind::Operating
                || ! $this->isOperatingInternal($user)
            )
        ) {
            return false;
        }

        return $this->authorization->allows(
            $user,
            $channel->organization,
            OrganizationAbility::ManageChannels->value,
        );
    }

    public function isOwner(User $user, Channel $channel): bool
    {
        return $channel->conversation->memberships()
            ->where('user_id', $user->getKey())
            ->where('channel_role', ChannelMembershipRole::Owner->value)
            ->whereNull('left_at')
            ->whereNull('removed_at')
            ->exists();
    }

    public function canManagePrivateMembership(User $user, Channel $channel): bool
    {
        return $channel->visibility === ChannelVisibility::Private
            && ($this->isOwner($user, $channel) || $this->canManage($user, $channel));
    }

    public function resolveVisible(User $user, string $publicId): Channel
    {
        return $this->visibleTo($user)
            ->whereHas(
                'conversation',
                fn (Builder $conversation): Builder => $conversation->where('public_id', $publicId),
            )
            ->firstOrFail();
    }

    public function resolveJoinable(User $user, string $publicId): Channel
    {
        if (! $this->isOperatingInternal($user)) {
            abort(404);
        }

        return Channel::query()
            ->with(['conversation.liveMeeting.organizer', 'organization'])
            ->where(function (Builder $joinable): void {
                $joinable->where(function (Builder $public): void {
                    $public->where('visibility', ChannelVisibility::Public->value)
                        ->whereHas(
                            'organization',
                            fn (Builder $organization): Builder => $organization
                                ->where('kind', OrganizationKind::Operating->value),
                        );
                })->orWhere('visibility', ChannelVisibility::ClientTeam->value);
            })
            ->whereHas(
                'conversation',
                fn (Builder $conversation): Builder => $conversation->where('public_id', $publicId),
            )
            ->firstOrFail();
    }

    public function resolveAddressable(User $user, string $publicId): Channel
    {
        $channel = Channel::query()
            ->with(['conversation.liveMeeting.organizer', 'organization'])
            ->whereHas(
                'conversation',
                fn (Builder $conversation): Builder => $conversation->where('public_id', $publicId),
            )
            ->firstOrFail();

        if (! $this->canRead($user, $channel) && ! $this->canManage($user, $channel)) {
            abort(404);
        }

        return $channel;
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
}
