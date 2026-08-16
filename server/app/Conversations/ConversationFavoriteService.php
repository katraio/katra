<?php

namespace App\Conversations;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class ConversationFavoriteService
{
    public function __construct(
        private readonly ChannelAccess $channels,
        private readonly DirectMessageAccess $directMessages,
    ) {}

    public function favoriteChannel(Channel $channel, User $user): Channel
    {
        $this->authorizeChannel($channel, $user);
        $this->favorite($channel->conversation, $user);

        return $channel;
    }

    public function unfavoriteChannel(Channel $channel, User $user): Channel
    {
        $this->authorizeChannel($channel, $user);
        $this->unfavorite($channel->conversation, $user);

        return $channel;
    }

    public function favoriteDirectMessage(DirectMessage $directMessage, User $user): DirectMessage
    {
        $this->authorizeDirectMessage($directMessage, $user);
        $this->favorite($directMessage->conversation, $user);

        return $directMessage;
    }

    public function unfavoriteDirectMessage(DirectMessage $directMessage, User $user): DirectMessage
    {
        $this->authorizeDirectMessage($directMessage, $user);
        $this->unfavorite($directMessage->conversation, $user);

        return $directMessage;
    }

    private function favorite(Conversation $conversation, User $user): void
    {
        $conversation->favoritedByUsers()->syncWithoutDetaching([$user->getKey()]);
    }

    private function unfavorite(Conversation $conversation, User $user): void
    {
        $conversation->favoritedByUsers()->detach($user->getKey());
    }

    private function authorizeChannel(Channel $channel, User $user): void
    {
        if (! $this->channels->canRead($user, $channel)) {
            throw new AuthorizationException;
        }
    }

    private function authorizeDirectMessage(DirectMessage $directMessage, User $user): void
    {
        if (! $this->directMessages->canRead($user, $directMessage)) {
            throw new AuthorizationException;
        }
    }
}
