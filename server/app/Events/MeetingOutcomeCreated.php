<?php

namespace App\Events;

use App\Models\MeetingOutcome;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class MeetingOutcomeCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public readonly string $eventId;

    public readonly string $meetingId;

    public readonly string $outcomeId;

    /** @var list<string> */
    public readonly array $participantIds;

    public function __construct(public readonly MeetingOutcome $outcome)
    {
        $outcome->loadMissing(['meeting.participants.user']);
        $this->eventId = (string) Str::ulid();
        $this->meetingId = $outcome->meeting->public_id;
        $this->outcomeId = $outcome->public_id;
        $this->participantIds = $outcome->meeting->participants
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
        return [
            new PresenceChannel("meetings.{$this->meetingId}"),
            ...array_map(
                fn (string $participantId): PrivateChannel => new PrivateChannel("users.{$participantId}"),
                $this->participantIds,
            ),
        ];
    }

    public function broadcastAs(): string
    {
        return 'meeting.outcome.created.v1';
    }

    /** @return array{event_id: string, version: int, meeting_id: string, outcome_id: string} */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'version' => 1,
            'meeting_id' => $this->meetingId,
            'outcome_id' => $this->outcomeId,
        ];
    }
}
