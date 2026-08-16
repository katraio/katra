<?php

namespace App\Conversations;

use App\Enums\ChannelVisibility;
use App\Enums\ConversationType;
use App\Enums\DirectMessageState;
use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class ConversationWriteAccess
{
    public function __construct(private readonly DirectMessageAccess $directMessages) {}

    public function authorize(Conversation $conversation, User $user): void
    {
        match ($conversation->type) {
            ConversationType::Channel => $this->authorizeChannel($conversation->channel, $user),
            ConversationType::DirectMessage => $this->authorizeDirectMessage(
                $conversation->directMessage,
                $user,
            ),
        };
    }

    private function authorizeChannel(?Channel $channel, User $user): void
    {
        if ($channel === null) {
            abort(404);
        }

        if ($channel->conversation->archived_at !== null) {
            throw ValidationException::withMessages([
                'conversation' => ['Archived Channels are read-only.'],
            ]);
        }

        if (
            $channel->visibility === ChannelVisibility::ClientTeam
            && $this->isActiveClientMember($user, $channel)
        ) {
            return;
        }

        if (! $this->hasActiveConversationMembership($channel->conversation, $user)) {
            throw new AuthorizationException;
        }
    }

    private function authorizeDirectMessage(?DirectMessage $directMessage, User $user): void
    {
        if ($directMessage === null || ! $this->directMessages->isParticipant($directMessage, $user)) {
            abort(404);
        }

        if ($this->directMessages->isInternalParticipant($directMessage, $user)) {
            return;
        }

        if (! $this->directMessages->isClientParticipant($directMessage, $user)) {
            throw new AuthorizationException;
        }

        if ($directMessage->state !== DirectMessageState::Open) {
            throw ValidationException::withMessages([
                'conversation' => ['This conversation must be reopened before a client can participate.'],
            ]);
        }
    }

    private function hasActiveConversationMembership(Conversation $conversation, User $user): bool
    {
        return $conversation->memberships()
            ->where('user_id', $user->getKey())
            ->whereNull('left_at')
            ->whereNull('removed_at')
            ->exists();
    }

    private function isActiveClientMember(User $user, Channel $channel): bool
    {
        return $user->organizationMemberships()
            ->where('organization_id', $channel->organization_id)
            ->where('kind', MembershipKind::Client->value)
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }
}
