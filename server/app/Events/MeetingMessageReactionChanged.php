<?php

namespace App\Events;

use App\Models\MeetingMessage;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class MeetingMessageReactionChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public readonly string $eventId;

    public readonly string $meetingId;

    public readonly string $messageId;

    public readonly int $messageSequence;

    public function __construct(public readonly MeetingMessage $message)
    {
        $message->loadMissing('meeting');
        $this->eventId = (string) Str::ulid();
        $this->meetingId = $message->meeting->public_id;
        $this->messageId = $message->public_id;
        $this->messageSequence = $message->sequence;
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("meetings.{$this->meetingId}");
    }

    public function broadcastAs(): string
    {
        return 'meeting.message.reaction.changed.v1';
    }

    /** @return array{event_id: string, version: int, meeting_id: string, message_id: string, message_sequence: int} */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'version' => 1,
            'meeting_id' => $this->meetingId,
            'message_id' => $this->messageId,
            'message_sequence' => $this->messageSequence,
        ];
    }
}
