<?php

namespace App\Meetings;

use App\Enums\MeetingStatus;
use App\Events\MeetingMessageReactionChanged;
use App\Models\Meeting;
use App\Models\MeetingMessage;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MeetingMessageReactionService
{
    /** @var list<string> */
    public const SUPPORTED_KINDS = ['approve', 'support'];

    public function add(Meeting $meeting, User $user, string $messageId, string $kind): MeetingMessage
    {
        return $this->mutate($meeting, $user, $messageId, $kind, true);
    }

    public function remove(Meeting $meeting, User $user, string $messageId, string $kind): MeetingMessage
    {
        return $this->mutate($meeting, $user, $messageId, $kind, false);
    }

    public function addAsGuest(Meeting $meeting, MeetingParticipant $participant, string $messageId, string $kind): MeetingMessage
    {
        return $this->mutateAsGuest($meeting, $participant, $messageId, $kind, true);
    }

    public function removeAsGuest(Meeting $meeting, MeetingParticipant $participant, string $messageId, string $kind): MeetingMessage
    {
        return $this->mutateAsGuest($meeting, $participant, $messageId, $kind, false);
    }

    private function mutate(Meeting $meeting, User $user, string $messageId, string $kind, bool $add): MeetingMessage
    {
        return DB::transaction(function () use ($meeting, $user, $messageId, $kind, $add): MeetingMessage {
            /** @var Meeting $locked */
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== MeetingStatus::Live || ! $locked->participants()->where('user_id', $user->getKey())->whereNull('removed_at')->exists()) {
                throw ValidationException::withMessages([
                    'meeting' => ['Meeting reactions are writable only by recorded participants while the meeting is live.'],
                ]);
            }

            /** @var MeetingMessage $message */
            $message = $locked->messages()->where('public_id', $messageId)->firstOrFail();
            $changed = false;

            if ($add) {
                $reaction = $message->reactions()->firstOrCreate([
                    'user_id' => $user->getKey(),
                    'kind' => $kind,
                ]);
                $changed = $reaction->wasRecentlyCreated;
            } else {
                $changed = $message->reactions()
                    ->where('user_id', $user->getKey())
                    ->where('kind', $kind)
                    ->delete() > 0;
            }

            if ($changed) {
                MeetingMessageReactionChanged::dispatch($message);
            }

            return $message->load(['author', 'guestAuthor', 'reactions']);
        });
    }

    private function mutateAsGuest(Meeting $meeting, MeetingParticipant $participant, string $messageId, string $kind, bool $add): MeetingMessage
    {
        return DB::transaction(function () use ($meeting, $participant, $messageId, $kind, $add): MeetingMessage {
            /** @var Meeting $locked */
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();
            $lockedParticipant = $locked->participants()
                ->whereKey($participant->getKey())
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->first();
            if ($locked->status !== MeetingStatus::Live || $lockedParticipant === null) {
                throw ValidationException::withMessages([
                    'meeting' => ['Meeting reactions are writable only by recorded participants while the meeting is live.'],
                ]);
            }

            /** @var MeetingMessage $message */
            $message = $locked->messages()->where('public_id', $messageId)->firstOrFail();
            if ($add) {
                $reaction = $message->reactions()->firstOrCreate([
                    'meeting_participant_id' => $lockedParticipant->getKey(),
                    'kind' => $kind,
                ]);
                $changed = $reaction->wasRecentlyCreated;
            } else {
                $changed = $message->reactions()
                    ->where('meeting_participant_id', $lockedParticipant->getKey())
                    ->where('kind', $kind)
                    ->delete() > 0;
            }

            if ($changed) {
                MeetingMessageReactionChanged::dispatch($message);
            }

            return $message->load(['author', 'guestAuthor', 'reactions']);
        });
    }
}
