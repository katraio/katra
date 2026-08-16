<?php

namespace App\Conversations;

use App\Enums\ChannelVisibility;
use App\Enums\ConversationType;
use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationKind;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class ConversationMentionService
{
    public function __construct(private readonly ConversationAccess $access) {}

    /** @return EloquentCollection<int, User> */
    public function candidates(User $actor, string $conversationPublicId): EloquentCollection
    {
        $conversation = $this->access->resolveReadable($actor, $conversationPublicId);

        return match ($conversation->type) {
            ConversationType::DirectMessage => $conversation->directMessage
                ->participants()
                ->whereKeyNot($actor->getKey())
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(),
            ConversationType::Channel => $this->channelCandidates($conversation, $actor),
        };
    }

    public function unreadCount(Conversation $conversation, User $user, int $lastReadSequence): int
    {
        return $conversation->messages()
            ->where('sequence', '>', $lastReadSequence)
            ->whereHas(
                'mentions',
                fn (Builder $mention): Builder => $mention
                    ->where('mentioned_user_id', $user->getKey()),
            )
            ->count();
    }

    /** @return EloquentCollection<int, User> */
    private function channelCandidates(Conversation $conversation, User $actor): EloquentCollection
    {
        $channel = $conversation->channel;

        if ($channel->visibility === ChannelVisibility::Private) {
            return User::query()
                ->whereKeyNot($actor->getKey())
                ->whereHas(
                    'conversationMemberships',
                    fn (Builder $membership): Builder => $membership
                        ->where('conversation_id', $conversation->getKey())
                        ->whereNull('left_at')
                        ->whereNull('removed_at'),
                )
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get();
        }

        return User::query()
            ->whereKeyNot($actor->getKey())
            ->where(function (Builder $mentionable) use ($channel): void {
                $mentionable->where(function (Builder $internal): void {
                    $internal
                        ->whereHas('roles', fn (Builder $role): Builder => $role->where('name', 'global-administrator'))
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

                if ($channel->visibility === ChannelVisibility::ClientTeam) {
                    $mentionable->orWhereHas(
                        'organizationMemberships',
                        fn (Builder $membership): Builder => $membership
                            ->where('organization_id', $channel->organization_id)
                            ->where('kind', MembershipKind::Client->value)
                            ->where('status', MembershipStatus::Active->value),
                    );
                }
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }
}
