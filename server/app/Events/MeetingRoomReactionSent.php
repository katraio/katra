<?php

namespace App\Events;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

final class MeetingRoomReactionSent implements ShouldBroadcastNow
{
    use Dispatchable;

    public readonly string $eventId;

    public readonly string $meetingId;

    public readonly string $actorUserId;

    public function __construct(
        Meeting $meeting,
        User|MeetingParticipant $actor,
        public readonly string $kind,
    ) {
        $this->eventId = (string) Str::ulid();
        $this->meetingId = $meeting->public_id;
        $this->actorUserId = $actor->public_id;
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("meetings.{$this->meetingId}");
    }

    public function broadcastAs(): string
    {
        return 'meeting.room.reaction.sent.v1';
    }

    /** @return array{event_id: string, version: int, meeting_id: string, actor_user_id: string, kind: string} */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'version' => 1,
            'meeting_id' => $this->meetingId,
            'actor_user_id' => $this->actorUserId,
            'kind' => $this->kind,
        ];
    }
}
