<?php

namespace App\Conversations;

use App\Auth\OrganizationAuthorization;
use App\Enums\ChannelMembershipRole;
use App\Enums\ChannelVisibility;
use App\Enums\ConversationType;
use App\Enums\OrganizationAbility;
use App\Enums\OrganizationKind;
use App\Events\ConversationAccessChanged;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationMembership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ChannelService
{
    public function __construct(
        private readonly ChannelAccess $access,
        private readonly OrganizationAuthorization $authorization,
    ) {}

    public function createInternal(
        Organization $organization,
        User $creator,
        string $name,
        ChannelVisibility $visibility,
    ): Channel {
        if ($organization->kind !== OrganizationKind::Operating) {
            throw ValidationException::withMessages([
                'organization' => ['Public and private Channels belong only to the operating Organization.'],
            ]);
        }

        if (! in_array($visibility, [ChannelVisibility::Public, ChannelVisibility::Private], true)) {
            throw ValidationException::withMessages([
                'visibility' => ['An internal Channel must be public or private.'],
            ]);
        }

        if (! $this->authorization->allows(
            $creator,
            $organization,
            OrganizationAbility::CreateInternalChannels->value,
        )) {
            throw new AuthorizationException;
        }

        [$name, $slug] = $this->validatedIdentity($organization, $name);

        return DB::transaction(function () use ($organization, $creator, $name, $slug, $visibility): Channel {
            $conversation = Conversation::query()->create([
                'organization_id' => $organization->getKey(),
                'type' => ConversationType::Channel,
                'created_by_user_id' => $creator->getKey(),
            ]);

            $channel = $conversation->channel()->create([
                'organization_id' => $organization->getKey(),
                'name' => $name,
                'slug' => $slug,
                'visibility' => $visibility,
            ]);

            $conversation->memberships()->create([
                'user_id' => $creator->getKey(),
                'channel_role' => ChannelMembershipRole::Owner,
                'joined_at' => now(),
                'added_by_user_id' => $creator->getKey(),
            ]);

            return $channel->load(['conversation', 'organization']);
        });
    }

    public function join(Channel $channel, User $user): ConversationMembership
    {
        $this->ensureMutable($channel);

        if (! $this->access->isOperatingInternal($user)) {
            throw new AuthorizationException;
        }

        if (! in_array($channel->visibility, [ChannelVisibility::Public, ChannelVisibility::ClientTeam], true)) {
            throw new AuthorizationException;
        }

        if (
            $channel->visibility === ChannelVisibility::Public
            && $channel->organization->kind !== OrganizationKind::Operating
        ) {
            throw new AuthorizationException;
        }

        [$membership] = $this->activateMembership($channel, $user, $user, ChannelMembershipRole::Member);

        return $membership;
    }

    public function inviteToPrivate(Channel $channel, User $actor, User $member): ConversationMembership
    {
        $this->ensureMutable($channel);

        if ($channel->visibility !== ChannelVisibility::Private) {
            throw ValidationException::withMessages([
                'channel' => ['Only private Channels use owner invitations.'],
            ]);
        }

        $this->authorizeOwnerOrManager($channel, $actor);

        if (! $this->access->isOperatingInternal($member)) {
            throw ValidationException::withMessages([
                'user' => ['Private internal Channels accept only active internal members.'],
            ]);
        }

        [$membership, $activated] = $this->activateMembership(
            $channel,
            $member,
            $actor,
            ChannelMembershipRole::Member,
        );

        if ($activated) {
            ConversationAccessChanged::dispatch($member, $channel->conversation, 'granted');
        }

        return $membership;
    }

    public function enrollMentionedInternal(
        Channel $channel,
        User $actor,
        User $member,
    ): ConversationMembership {
        $this->ensureMutable($channel);

        if (
            $channel->visibility !== ChannelVisibility::ClientTeam
            || ! $this->access->canRead($actor, $channel)
            || ! $this->access->isOperatingInternal($member)
        ) {
            throw new AuthorizationException;
        }

        [$membership] = $this->activateMembership($channel, $member, $actor, ChannelMembershipRole::Member);

        return $membership;
    }

    public function leave(Channel $channel, User $user): void
    {
        $this->ensureMutable($channel);

        if (
            $channel->visibility === ChannelVisibility::ClientTeam
            && ! $this->access->isOperatingInternal($user)
        ) {
            throw ValidationException::withMessages([
                'membership' => ['Client-team access is managed through Organization membership.'],
            ]);
        }

        DB::transaction(function () use ($channel, $user): void {
            $this->lockConversation($channel);
            $membership = $this->lockedActiveMembership($channel, $user);
            $this->ensureOwnerCanExit($channel, $membership);
            $membership->forceFill(['left_at' => now()])->save();
        });

        ConversationAccessChanged::dispatch($user, $channel->conversation, 'revoked');
    }

    public function removeInternalMember(Channel $channel, User $actor, User $member): void
    {
        $this->ensureMutable($channel);

        if (! $this->access->isOperatingInternal($member)) {
            throw ValidationException::withMessages([
                'user' => ['Client access must be changed through Organization membership.'],
            ]);
        }

        if ($channel->visibility === ChannelVisibility::Private) {
            $this->authorizeOwnerOrManager($channel, $actor);
        } elseif (! $this->canManage($channel, $actor)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($channel, $member): void {
            $this->lockConversation($channel);
            $membership = $this->lockedActiveMembership($channel, $member);
            $this->ensureOwnerCanExit($channel, $membership);
            $membership->forceFill(['removed_at' => now()])->save();
        });

        ConversationAccessChanged::dispatch($member, $channel->conversation, 'revoked');
    }

    public function promoteOwner(Channel $channel, User $actor, User $member): ConversationMembership
    {
        $this->ensureMutable($channel);

        if ($channel->visibility === ChannelVisibility::ClientTeam) {
            throw ValidationException::withMessages([
                'channel' => ['Client-team Channels do not have ordinary owners.'],
            ]);
        }

        $this->authorizeOwnerOrManager($channel, $actor);

        $membership = $this->lockedActiveMembership($channel, $member);
        $membership->forceFill(['channel_role' => ChannelMembershipRole::Owner])->save();

        return $membership;
    }

    public function demoteOwner(Channel $channel, User $actor, User $member): ConversationMembership
    {
        $this->ensureMutable($channel);

        if ($channel->visibility === ChannelVisibility::ClientTeam) {
            throw ValidationException::withMessages([
                'channel' => ['Client-team Channels do not have ordinary owners.'],
            ]);
        }

        $this->authorizeOwnerOrManager($channel, $actor);

        return DB::transaction(function () use ($channel, $member): ConversationMembership {
            $this->lockConversation($channel);
            $membership = $this->lockedActiveMembership($channel, $member);

            if ($membership->channel_role !== ChannelMembershipRole::Owner) {
                return $membership;
            }

            $this->ensureOwnerCanExit($channel, $membership);
            $membership->forceFill(['channel_role' => ChannelMembershipRole::Member])->save();

            return $membership;
        });
    }

    public function rename(Channel $channel, User $actor, string $name): Channel
    {
        $this->ensureMutable($channel);

        if ($channel->visibility === ChannelVisibility::ClientTeam) {
            if (! $this->canManage($channel, $actor)) {
                throw new AuthorizationException;
            }
        } else {
            $this->authorizeOwnerOrManager($channel, $actor);
        }

        [$name, $slug] = $this->validatedIdentity($channel->organization, $name, $channel);
        $channel->forceFill(['name' => $name, 'slug' => $slug])->save();

        return $channel;
    }

    public function archive(Channel $channel, User $actor): void
    {
        if (! $this->canManage($channel, $actor)) {
            throw new AuthorizationException;
        }

        if ($channel->conversation->archived_at === null) {
            $channel->conversation->forceFill(['archived_at' => now()])->save();
        }
    }

    /** @return array{ConversationMembership, bool} */
    private function activateMembership(
        Channel $channel,
        User $member,
        User $actor,
        ChannelMembershipRole $role,
    ): array {
        return DB::transaction(function () use ($channel, $member, $actor, $role): array {
            $membership = ConversationMembership::query()
                ->where('conversation_id', $channel->conversation_id)
                ->where('user_id', $member->getKey())
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                return [ConversationMembership::query()->create([
                    'conversation_id' => $channel->conversation_id,
                    'user_id' => $member->getKey(),
                    'channel_role' => $role,
                    'joined_at' => now(),
                    'added_by_user_id' => $actor->getKey(),
                ]), true];
            }

            if ($membership->isActive()) {
                return [$membership, false];
            }

            $membership->forceFill([
                'channel_role' => $role,
                'joined_at' => now(),
                'left_at' => null,
                'removed_at' => null,
                'added_by_user_id' => $actor->getKey(),
            ])->save();

            return [$membership, true];
        });
    }

    private function lockedActiveMembership(Channel $channel, User $user): ConversationMembership
    {
        return ConversationMembership::query()
            ->where('conversation_id', $channel->conversation_id)
            ->where('user_id', $user->getKey())
            ->whereNull('left_at')
            ->whereNull('removed_at')
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockConversation(Channel $channel): void
    {
        Conversation::query()->whereKey($channel->conversation_id)->lockForUpdate()->firstOrFail();
    }

    private function authorizeOwnerOrManager(Channel $channel, User $actor): void
    {
        if (! $this->isOwner($channel, $actor) && ! $this->canManage($channel, $actor)) {
            throw new AuthorizationException;
        }
    }

    private function isOwner(Channel $channel, User $user): bool
    {
        return $channel->conversation->memberships()
            ->where('user_id', $user->getKey())
            ->where('channel_role', ChannelMembershipRole::Owner->value)
            ->whereNull('left_at')
            ->whereNull('removed_at')
            ->exists();
    }

    private function canManage(Channel $channel, User $user): bool
    {
        return $this->access->canManage($user, $channel);
    }

    private function ensureOwnerCanExit(Channel $channel, ConversationMembership $membership): void
    {
        if ($membership->channel_role !== ChannelMembershipRole::Owner) {
            return;
        }

        $otherOwners = $channel->conversation->memberships()
            ->whereKeyNot($membership->getKey())
            ->where('channel_role', ChannelMembershipRole::Owner->value)
            ->whereNull('left_at')
            ->whereNull('removed_at')
            ->exists();

        if (! $otherOwners) {
            throw ValidationException::withMessages([
                'membership' => ['Transfer ownership before the final owner leaves or is removed.'],
            ]);
        }
    }

    /** @return array{string, string} */
    private function validatedIdentity(
        Organization $organization,
        string $name,
        ?Channel $except = null,
    ): array {
        $name = trim($name);
        $slug = Str::slug($name);

        if ($name === '' || mb_strlen($name) > 255 || $slug === '') {
            throw ValidationException::withMessages([
                'name' => ['Provide a Channel name of no more than 255 characters.'],
            ]);
        }

        $exists = Channel::query()
            ->where('organization_id', $organization->getKey())
            ->where('slug', $slug)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => ['A Channel with this name already exists in the Organization.'],
            ]);
        }

        return [$name, $slug];
    }

    private function ensureMutable(Channel $channel): void
    {
        if ($channel->conversation->archived_at !== null) {
            throw ValidationException::withMessages([
                'channel' => ['Archived Channels are read-only.'],
            ]);
        }
    }
}
