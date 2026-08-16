<?php

namespace App\Conversations;

use App\Events\ConversationReadPositionAdvanced;
use App\Models\ConversationMembership;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class ConversationReadService
{
    public function __construct(
        private readonly ConversationAccess $access,
        private readonly ConversationReadState $readState,
        private readonly ConversationMentionService $mentions,
    ) {}

    /** @return array{conversation_id: string, last_read_sequence: int, latest_sequence: int, unread_count: int, mention_count: int} */
    public function advance(User $user, string $conversationPublicId, int $throughSequence): array
    {
        $conversation = $this->access->resolveReadable($user, $conversationPublicId);
        /** @var ConversationMembership|null $membership */
        $membership = $conversation->memberships()
            ->where('user_id', $user->getKey())
            ->whereNull('left_at')
            ->whereNull('removed_at')
            ->first();

        if ($membership === null) {
            throw ValidationException::withMessages([
                'conversation' => ['Join this conversation before tracking read state.'],
            ]);
        }

        $previousSequence = $membership->last_read_sequence;
        $membership = $this->readState->advance($membership, $throughSequence);
        $latestSequence = $membership->conversation->next_message_sequence - 1;

        $state = [
            'conversation_id' => $membership->conversation->public_id,
            'last_read_sequence' => $membership->last_read_sequence,
            'latest_sequence' => $latestSequence,
            'unread_count' => max(0, $latestSequence - $membership->last_read_sequence),
            'mention_count' => $this->mentions->unreadCount(
                $membership->conversation,
                $user,
                $membership->last_read_sequence,
            ),
        ];

        if ($membership->last_read_sequence > $previousSequence) {
            ConversationReadPositionAdvanced::dispatch($user, $state);
        }

        return $state;
    }
}
