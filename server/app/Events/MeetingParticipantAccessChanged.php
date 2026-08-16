<?php

namespace App\Events;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class MeetingParticipantAccessChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public readonly string $meetingId;

    public readonly string $participantId;

    /** @param 'removed'|'restored' $operation */
    public function __construct(
        Meeting $meeting,
        MeetingParticipant $participant,
        public readonly string $operation,
    ) {
        $this->eventId = (string) Str::ulid();
        $this->meetingId = $meeting->public_id;
        $this->participantId = $participant->public_id;
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("meetings.{$this->meetingId}");
    }

    public function broadcastAs(): string
    {
        return 'meeting.participant.access.changed.v1';
    }

    /** @return array{event_id: string, version: int, meeting_id: string, participant_id: string, operation: string} */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'version' => 1,
            'meeting_id' => $this->meetingId,
            'participant_id' => $this->participantId,
            'operation' => $this->operation,
        ];
    }
}
