<?php

namespace App\Meetings;

use App\Enums\MeetingStatus;
use App\Events\MeetingStateChanged;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class MeetingRoomService
{
    public function __construct(private readonly MeetingMediaService $media) {}

    public function start(Meeting $meeting, User $actor): Meeting
    {
        return DB::transaction(function () use ($meeting, $actor): Meeting {
            $locked = $this->locked($meeting);
            $this->assertOrganizer($locked, $actor);

            if ($locked->status === MeetingStatus::Live) {
                return $this->loaded($locked);
            }

            if ($locked->status !== MeetingStatus::Scheduled) {
                throw new MeetingRoomTransitionException('Only a scheduled meeting can be started.');
            }

            $locked->forceFill([
                'status' => MeetingStatus::Live,
                'started_at' => now(),
            ])->save();
            MeetingStateChanged::dispatch($locked);

            return $this->loaded($locked);
        });
    }

    public function enter(Meeting $meeting, User $actor): Meeting
    {
        return DB::transaction(function () use ($meeting, $actor): Meeting {
            $locked = $this->locked($meeting);

            if ($locked->status !== MeetingStatus::Live) {
                throw new MeetingRoomTransitionException(
                    $locked->status === MeetingStatus::Scheduled
                        ? 'The organizer has not started this meeting yet.'
                        : 'This meeting is no longer open.',
                );
            }

            $participant = $locked->participants()
                ->where('user_id', $actor->getKey())
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->firstOrFail();
            $participant->forceFill([
                'joined_at' => $participant->joined_at ?? now(),
                'left_at' => null,
            ])->save();

            return $this->loaded($locked);
        });
    }

    public function leave(Meeting $meeting, User $actor): Meeting
    {
        return DB::transaction(function () use ($meeting, $actor): Meeting {
            $locked = $this->locked($meeting);

            if ($locked->organizer_user_id === $actor->getKey() && $locked->status === MeetingStatus::Live) {
                return $this->completeLocked($locked);
            }

            $participant = $locked->participants()
                ->where('user_id', $actor->getKey())
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->firstOrFail();

            if ($participant->joined_at !== null && $participant->left_at === null) {
                $participant->forceFill(['left_at' => now()])->save();
            }

            return $this->loaded($locked);
        });
    }

    public function complete(Meeting $meeting, User $actor): Meeting
    {
        return DB::transaction(function () use ($meeting, $actor): Meeting {
            $locked = $this->locked($meeting);
            $this->assertOrganizer($locked, $actor);

            if ($locked->status === MeetingStatus::Completed) {
                return $this->loaded($locked);
            }

            if ($locked->status !== MeetingStatus::Live) {
                throw new MeetingRoomTransitionException('Only a live meeting can be ended.');
            }

            return $this->completeLocked($locked);
        });
    }

    public function cancel(Meeting $meeting, User $actor): Meeting
    {
        return DB::transaction(function () use ($meeting, $actor): Meeting {
            $locked = $this->locked($meeting);
            $this->assertOrganizer($locked, $actor);

            if ($locked->status === MeetingStatus::Cancelled) {
                return $this->loaded($locked);
            }

            if ($locked->status !== MeetingStatus::Scheduled) {
                throw new MeetingRoomTransitionException('Only a scheduled meeting can be cancelled.');
            }

            $locked->forceFill([
                'status' => MeetingStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();
            MeetingStateChanged::dispatch($locked);

            return $this->loaded($locked);
        });
    }

    private function locked(Meeting $meeting): Meeting
    {
        return Meeting::query()->lockForUpdate()->findOrFail($meeting->getKey());
    }

    private function assertOrganizer(Meeting $meeting, User $actor): void
    {
        if ($meeting->organizer_user_id !== $actor->getKey()) {
            throw new AuthorizationException;
        }
    }

    private function completeLocked(Meeting $meeting): Meeting
    {
        $endedAt = now();
        $meeting->forceFill([
            'status' => MeetingStatus::Completed,
            'ended_at' => $endedAt,
        ])->save();
        $meeting->participants()
            ->whereNotNull('joined_at')
            ->whereNull('left_at')
            ->update(['left_at' => $endedAt, 'updated_at' => $endedAt]);
        $this->media->retireAfterMeeting($meeting);
        MeetingStateChanged::dispatch($meeting);

        return $this->loaded($meeting);
    }

    private function loaded(Meeting $meeting): Meeting
    {
        return $meeting->fresh([
            'organization',
            'organizer',
            'participants.user',
            'participants.invitation',
            'invitations.participant',
            'agendaItems.owner',
            'outcomes.author',
            'outcomes.guestAuthor',
            'outcomes.assignee',
            'outcomes.attentionItem',
        ]);
    }
}
