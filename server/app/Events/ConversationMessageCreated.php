<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class ConversationMessageCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public readonly string $conversationId;

    public readonly string $messageId;

    public readonly int $sequence;

    /** @var list<string> */
    public readonly array $mentionedUserIds;

    /** @var list<string> */
    public readonly array $attentionUserIds;

    public function __construct(Message $message)
    {
        $message->loadMissing([
            'conversation',
            'mentions.mentionedUser',
            'attentionTargets.targetedUser',
        ]);

        $this->eventId = (string) Str::ulid();
        $this->conversationId = $message->conversation->public_id;
        $this->messageId = $message->public_id;
        $this->sequence = $message->sequence;
        $this->mentionedUserIds = $message->mentions
            ->map(fn ($mention): string => $mention->mentionedUser->public_id)
            ->sort()
            ->values()
            ->all();
        $this->attentionUserIds = $message->attentionTargets
            ->map(fn ($target): string => $target->targetedUser->public_id)
            ->sort()
            ->values()
            ->all();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("conversations.{$this->conversationId}");
    }

    public function broadcastAs(): string
    {
        return 'conversation.message.created.v1';
    }

    /** @return array<string, int|string|list<string>> */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'version' => 1,
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'sequence' => $this->sequence,
            'mentioned_user_ids' => $this->mentionedUserIds,
            'attention_user_ids' => $this->attentionUserIds,
        ];
    }
}
