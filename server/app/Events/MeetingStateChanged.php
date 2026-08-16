<?php

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class MeetingStateChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public readonly string $meetingId;

    public readonly ?string $conversationId;

    /** @var list<string> */
    public readonly array $participantIds;

    public function __construct(public readonly Meeting $meeting)
    {
        $meeting->loadMissing(['participants.user', 'conversation']);
        $this->eventId = (string) Str::ulid();
        $this->meetingId = $meeting->public_id;
        $this->conversationId = $meeting->conversation?->public_id;
        $this->participantIds = $meeting->participants
            ->where('kind', 'user')
            ->whereNull('removed_at')
            ->pluck('user.public_id')
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int, PresenceChannel|PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PresenceChannel("meetings.{$this->meetingId}"),
            ...array_map(
                fn (string $participantId): PrivateChannel => new PrivateChannel("users.{$participantId}"),
                $this->participantIds,
            ),
        ];

        if ($this->conversationId !== null) {
            $channels[] = new PrivateChannel("conversations.{$this->conversationId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'meeting.state.changed.v1';
    }

    /** @return array{event_id: string, version: int, meeting_id: string, conversation_id: ?string, status: string} */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'version' => 1,
            'meeting_id' => $this->meetingId,
            'conversation_id' => $this->conversationId,
            'status' => $this->meeting->status->value,
        ];
    }
}
