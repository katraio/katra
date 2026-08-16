<?php

namespace App\Events;

use App\Models\AttentionItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class AttentionItemChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public readonly string $attentionId;

    public readonly string $userId;

    public function __construct(AttentionItem $item, public readonly string $operation)
    {
        $item->loadMissing('user');

        $this->eventId = (string) Str::ulid();
        $this->attentionId = $item->public_id;
        $this->userId = $item->user->public_id;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'attention.item.changed.v1';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'version' => 1,
            'attention_id' => $this->attentionId,
            'operation' => $this->operation,
        ];
    }
}
