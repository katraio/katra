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

final class ConversationReactionChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public readonly string $conversationId;

    public readonly string $messageId;

    public readonly int $messageSequence;

    public function __construct(Message $message)
    {
        $message->loadMissing('conversation');

        $this->eventId = (string) Str::ulid();
        $this->conversationId = $message->conversation->public_id;
        $this->messageId = $message->public_id;
        $this->messageSequence = $message->sequence;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("conversations.{$this->conversationId}");
    }

    public function broadcastAs(): string
    {
        return 'conversation.reaction.changed.v1';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'version' => 1,
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'message_sequence' => $this->messageSequence,
        ];
    }
}
