<?php

namespace App\Events;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class MeetingAccessChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public readonly string $userId;

    public readonly string $meetingId;

    /** @param 'granted'|'revoked' $operation */
    public function __construct(
        User $user,
        Meeting $meeting,
        public readonly string $operation,
    ) {
        $this->eventId = (string) Str::ulid();
        $this->userId = $user->public_id;
        $this->meetingId = $meeting->public_id;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'meeting.access.changed.v1';
    }

    /** @return array{event_id: string, version: int, meeting_id: string, operation: string} */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'version' => 1,
            'meeting_id' => $this->meetingId,
            'operation' => $this->operation,
        ];
    }
}
