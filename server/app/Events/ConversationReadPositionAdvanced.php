<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class ConversationReadPositionAdvanced implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public readonly string $userId;

    /**
     * @param  array{conversation_id: string, last_read_sequence: int, latest_sequence: int, unread_count: int, mention_count: int}  $readState
     */
    public function __construct(User $user, public readonly array $readState)
    {
        $this->eventId = (string) Str::ulid();
        $this->userId = $user->public_id;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'conversation.read-position.advanced.v1';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'version' => 1,
            ...$this->readState,
        ];
    }
}
