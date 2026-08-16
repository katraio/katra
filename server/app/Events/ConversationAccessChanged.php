<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class ConversationAccessChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public readonly string $userId;

    public readonly string $conversationId;

    /** @param 'granted'|'revoked' $operation */
    public function __construct(
        User $user,
        Conversation $conversation,
        public readonly string $operation,
    ) {
        $this->eventId = (string) Str::ulid();
        $this->userId = $user->public_id;
        $this->conversationId = $conversation->public_id;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'conversation.access.changed.v1';
    }

    /** @return array{event_id: string, version: int, conversation_id: string, operation: string} */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'version' => 1,
            'conversation_id' => $this->conversationId,
            'operation' => $this->operation,
        ];
    }
}
