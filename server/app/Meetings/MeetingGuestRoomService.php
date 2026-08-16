<?php

namespace App\Meetings;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingGuestSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MeetingGuestRoomService
{
    public function enter(MeetingGuestSession $session): Meeting
    {
        return DB::transaction(function () use ($session): Meeting {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($session->meeting_id);
            if ($meeting->status !== MeetingStatus::Live) {
                throw ValidationException::withMessages([
                    'meeting' => [$meeting->status === MeetingStatus::Scheduled
                        ? 'The organizer has not started this meeting yet.'
                        : 'This meeting is no longer open.'],
                ]);
            }

            $participant = $meeting->participants()
                ->whereKey($session->meeting_participant_id)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->firstOrFail();
            $participant->forceFill([
                'joined_at' => $participant->joined_at ?? now(),
                'left_at' => null,
            ])->save();

            return $meeting;
        });
    }

    public function leave(MeetingGuestSession $session): Meeting
    {
        return DB::transaction(function () use ($session): Meeting {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($session->meeting_id);
            $participant = $meeting->participants()
                ->whereKey($session->meeting_participant_id)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->firstOrFail();
            if ($participant->joined_at !== null && $participant->left_at === null) {
                $participant->forceFill(['left_at' => now()])->save();
            }

            return $meeting;
        });
    }
}
