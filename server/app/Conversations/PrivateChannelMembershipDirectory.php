<?php

namespace App\Conversations;

use App\Enums\ChannelVisibility;
use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationKind;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\ConversationMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class PrivateChannelMembershipDirectory
{
    public function __construct(private readonly ChannelAccess $access) {}

    /** @return EloquentCollection<int, ConversationMembership> */
    public function members(User $actor, Channel $channel): EloquentCollection
    {
        if (
            $channel->visibility !== ChannelVisibility::Private
            || (! $this->access->canRead($actor, $channel)
                && ! $this->access->canManagePrivateMembership($actor, $channel))
        ) {
            abort(404);
        }

        return $channel->conversation->memberships()
            ->with('user')
            ->whereNull('left_at')
            ->whereNull('removed_at')
            ->whereHas('user', fn (Builder $user): Builder => $this->operatingInternal($user))
            ->orderByRaw("case when channel_role = 'owner' then 0 else 1 end")
            ->orderBy('joined_at')
            ->get();
    }

    /** @return EloquentCollection<int, User> */
    public function candidates(User $actor, Channel $channel, string $query = '', int $limit = 20): EloquentCollection
    {
        if (! $this->access->canManagePrivateMembership($actor, $channel)) {
            throw new AuthorizationException;
        }

        $normalized = mb_strtolower(trim($query));

        return User::query()
            ->where(fn (Builder $user): Builder => $this->operatingInternal($user))
            ->whereDoesntHave(
                'conversationMemberships',
                fn (Builder $membership): Builder => $membership
                    ->where('conversation_id', $channel->conversation_id)
                    ->whereNull('left_at')
                    ->whereNull('removed_at'),
            )
            ->when($normalized !== '', function (Builder $users) use ($normalized): void {
                $users->whereRaw(
                    "position(? in lower(first_name || ' ' || last_name)) > 0",
                    [$normalized],
                );
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(max(1, min(50, $limit)))
            ->get();
    }

    public function resolveInternal(string $publicId): User
    {
        return User::query()
            ->where('public_id', strtoupper($publicId))
            ->where(fn (Builder $user): Builder => $this->operatingInternal($user))
            ->firstOrFail();
    }

    private function operatingInternal(Builder $user): Builder
    {
        return $user->where(function (Builder $internal): void {
            $internal
                ->whereHas(
                    'roles',
                    fn (Builder $role): Builder => $role->where('name', SystemRole::GlobalAdministrator->value),
                )
                ->orWhereHas(
                    'organizationMemberships',
                    fn (Builder $membership): Builder => $membership
                        ->where('kind', MembershipKind::Internal->value)
                        ->where('status', MembershipStatus::Active->value)
                        ->whereHas(
                            'organization',
                            fn (Builder $organization): Builder => $organization
                                ->where('kind', OrganizationKind::Operating->value),
                        ),
                );
        });
    }
}
