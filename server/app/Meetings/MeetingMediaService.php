<?php

namespace App\Meetings;

use App\Enums\MeetingStatus;
use App\Jobs\RetireMeetingMediaRoom;
use App\Models\Meeting;
use App\Models\MeetingGuestSession;
use App\Models\MeetingParticipant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Katra\LiveKit\Contracts\AccessTokenFactory;
use Katra\LiveKit\ParticipantGrant;

final class MeetingMediaService
{
    public function __construct(private readonly AccessTokenFactory $tokens) {}

    /**
     * @return array{url: string, token: string, expires_at: string, room_generation: int, participant_identity: string}
     */
    public function credentialForUser(Meeting $meeting, User $user): array
    {
        $context = DB::transaction(function () use ($meeting, $user): array {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->getKey());
            $this->assertLive($locked);

            $participant = $locked->participants()
                ->where('user_id', $user->getKey())
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->firstOrFail();

            return $this->credentialContext($locked, $participant);
        });

        return $this->issue($context);
    }

    /**
     * @return array{url: string, token: string, expires_at: string, room_generation: int, participant_identity: string}
     */
    public function credentialForGuest(MeetingGuestSession $session): array
    {
        $context = DB::transaction(function () use ($session): array {
            $lockedMeeting = Meeting::query()->lockForUpdate()->findOrFail($session->meeting_id);
            $this->assertLive($lockedMeeting);

            $lockedSession = MeetingGuestSession::query()
                ->whereKey($session->getKey())
                ->where('meeting_id', $lockedMeeting->getKey())
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->firstOrFail();
            $participant = $lockedMeeting->participants()
                ->whereKey($lockedSession->meeting_participant_id)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->firstOrFail();

            return $this->credentialContext($lockedMeeting, $participant);
        });

        return $this->issue($context);
    }

    public function rotateAfterParticipantRemoval(Meeting $meeting, MeetingParticipant $participant): void
    {
        if ($meeting->status !== MeetingStatus::Live || $meeting->media_room_name === null) {
            return;
        }

        $retiredRoom = $meeting->media_room_name;
        $meeting->forceFill([
            'media_room_name' => $this->newRoomName(),
            'media_room_generation' => $meeting->media_room_generation + 1,
        ])->save();

        RetireMeetingMediaRoom::dispatch(
            $retiredRoom,
            $this->participantIdentity($participant),
        )->afterCommit();
    }

    public function retireAfterMeeting(Meeting $meeting): void
    {
        if ($meeting->media_room_name === null) {
            return;
        }

        $retiredRoom = $meeting->media_room_name;
        $meeting->forceFill(['media_room_name' => null])->save();
        RetireMeetingMediaRoom::dispatch($retiredRoom)->afterCommit();
    }

    /**
     * @return array{room_name: string, room_generation: int, participant_identity: string}
     */
    private function credentialContext(Meeting $meeting, MeetingParticipant $participant): array
    {
        if ($meeting->media_room_name === null) {
            $meeting->forceFill([
                'media_room_name' => $this->newRoomName(),
                'media_room_generation' => $meeting->media_room_generation + 1,
            ])->save();
        }

        return [
            'room_name' => $meeting->media_room_name,
            'room_generation' => $meeting->media_room_generation,
            'participant_identity' => $this->participantIdentity($participant),
        ];
    }

    /**
     * @param  array{room_name: string, room_generation: int, participant_identity: string}  $context
     * @return array{url: string, token: string, expires_at: string, room_generation: int, participant_identity: string}
     */
    private function issue(array $context): array
    {
        $ttl = (int) config('livekit.join_token_ttl', 120);
        $expiresAt = CarbonImmutable::now()->addSeconds($ttl);

        return [
            'url' => (string) config('livekit.public_url'),
            'token' => $this->tokens->participant(new ParticipantGrant(
                $context['room_name'],
                $context['participant_identity'],
            )),
            'expires_at' => $expiresAt->toIso8601String(),
            'room_generation' => $context['room_generation'],
            'participant_identity' => $context['participant_identity'],
        ];
    }

    private function assertLive(Meeting $meeting): void
    {
        if ($meeting->status !== MeetingStatus::Live) {
            throw ValidationException::withMessages([
                'meeting' => [$meeting->status === MeetingStatus::Scheduled
                    ? 'The organizer has not started this meeting yet.'
                    : 'This meeting is no longer open.'],
            ]);
        }
    }

    private function participantIdentity(MeetingParticipant $participant): string
    {
        return 'mp_'.Str::lower($participant->public_id);
    }

    private function newRoomName(): string
    {
        return 'kr_'.Str::lower((string) Str::ulid());
    }
}
