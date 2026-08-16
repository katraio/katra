<?php

namespace App\Meetings;

use App\Enums\MeetingStatus;
use App\Events\MeetingAccessChanged;
use App\Events\MeetingParticipantAccessChanged;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MeetingParticipantAccessService
{
    public function __construct(
        private readonly MeetingGuestSecurityMonitor $securityMonitor,
        private readonly MeetingMediaService $media,
    ) {}

    public function remove(
        Meeting $meeting,
        MeetingParticipant $participant,
        User $actor,
        bool $blockReentry,
    ): Meeting {
        if ($meeting->organizer_user_id !== $actor->getKey()) {
            throw new AuthorizationException;
        }
        if (! in_array($meeting->status, [MeetingStatus::Scheduled, MeetingStatus::Live], true)) {
            throw ValidationException::withMessages([
                'meeting' => ['Participants can be removed only while a meeting is scheduled or live.'],
            ]);
        }
        if ($participant->meeting_id !== $meeting->getKey()) {
            abort(404);
        }
        if ($participant->user_id === $meeting->organizer_user_id) {
            throw ValidationException::withMessages([
                'participant' => ['The meeting organizer cannot be removed.'],
            ]);
        }
        if ($blockReentry && $participant->guest_admission_source !== 'email-invitation') {
            throw ValidationException::withMessages([
                'block_reentry' => ['Only a guest admitted by email can be individually blocked from re-entering.'],
            ]);
        }

        return DB::transaction(function () use ($meeting, $participant, $actor, $blockReentry): Meeting {
            $lockedMeeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->getKey());
            $locked = $lockedMeeting->participants()
                ->whereKey($participant->getKey())
                ->with(['user', 'invitation'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->removed_at !== null) {
                return $this->loaded($lockedMeeting);
            }

            $removedAt = now();
            $locked->forceFill([
                'removed_by_user_id' => $actor->getKey(),
                'removed_at' => $removedAt,
                'left_at' => $locked->joined_at !== null && $locked->left_at === null
                    ? $removedAt
                    : $locked->left_at,
            ])->save();

            $sessionRevoked = false;
            $session = $locked->guestSession()->lockForUpdate()->first();
            if ($session !== null && $session->revoked_at === null) {
                $session->forceFill(['revoked_at' => $removedAt])->save();
                $sessionRevoked = true;
            }

            $invitationBlocked = false;
            if ($blockReentry) {
                $invitation = $locked->invitation;
                if ($invitation === null) {
                    throw ValidationException::withMessages([
                        'block_reentry' => ['This participant has no email invitation to block.'],
                    ]);
                }
                if ($invitation->revoked_at === null) {
                    $invitation->forceFill(['revoked_at' => $removedAt])->save();
                    $invitation->events()->create([
                        'kind' => 'revoked',
                        'actor_user_id' => $actor->getKey(),
                    ]);
                    $invitationBlocked = true;
                }
            }

            $locked->events()->create([
                'meeting_id' => $lockedMeeting->getKey(),
                'kind' => 'removed',
                'actor_user_id' => $actor->getKey(),
            ]);

            $this->media->rotateAfterParticipantRemoval($lockedMeeting, $locked);

            MeetingParticipantAccessChanged::dispatch($lockedMeeting, $locked, 'removed');
            if ($locked->user !== null) {
                MeetingAccessChanged::dispatch($locked->user, $lockedMeeting, 'revoked');
            }

            DB::afterCommit(function () use ($sessionRevoked, $invitationBlocked): void {
                $this->securityMonitor->record('participant-removed');
                if ($sessionRevoked) {
                    $this->securityMonitor->record('session-revoked');
                }
                if ($invitationBlocked) {
                    $this->securityMonitor->record('invitation-blocked');
                }
            });

            return $this->loaded($lockedMeeting);
        });
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
