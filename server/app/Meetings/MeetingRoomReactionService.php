<?php

namespace App\Meetings;

use App\Enums\MeetingStatus;
use App\Events\MeetingRoomReactionSent;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class MeetingRoomReactionService
{
    /** @var list<string> */
    public const SUPPORTED_KINDS = ['approve', 'support', 'celebrate', 'raise-hand', 'lower-hand'];

    public function send(Meeting $meeting, User $actor, string $kind): void
    {
        $meeting->refresh();
        if ($meeting->status !== MeetingStatus::Live || ! $meeting->participants()->where('user_id', $actor->getKey())->whereNull('removed_at')->exists()) {
            throw ValidationException::withMessages([
                'meeting' => ['Room reactions are available only to recorded participants while the meeting is live.'],
            ]);
        }

        MeetingRoomReactionSent::dispatch($meeting, $actor, $kind);
    }

    public function sendAsGuest(Meeting $meeting, MeetingParticipant $actor, string $kind): void
    {
        $meeting->refresh();
        $activeActor = $meeting->participants()
            ->whereKey($actor->getKey())
            ->whereNull('removed_at')
            ->first();
        if ($meeting->status !== MeetingStatus::Live || $activeActor === null) {
            throw ValidationException::withMessages([
                'meeting' => ['Room reactions are available only to recorded participants while the meeting is live.'],
            ]);
        }

        MeetingRoomReactionSent::dispatch($meeting, $activeActor, $kind);
    }
}
