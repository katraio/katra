<?php

namespace App\Conversations;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;

final class ConversationAccess
{
    public function __construct(
        private readonly ChannelAccess $channels,
        private readonly DirectMessageAccess $directMessages,
    ) {}

    public function resolveReadable(User $user, string $publicId): Conversation
    {
        $conversation = Conversation::query()
            ->with([
                'channel.organization',
                'directMessage.organization',
            ])
            ->where('public_id', $publicId)
            ->firstOrFail();

        abort_unless($this->canRead($user, $conversation), 404);

        return $conversation;
    }

    public function canRead(User $user, Conversation $conversation): bool
    {
        $conversation->loadMissing([
            'channel.organization',
            'directMessage.organization',
        ]);

        return match ($conversation->type) {
            ConversationType::Channel => $conversation->channel !== null
                && $this->channels->canRead($user, $conversation->channel),
            ConversationType::DirectMessage => $conversation->directMessage !== null
                && $this->directMessages->canRead($user, $conversation->directMessage),
        };
    }
}
