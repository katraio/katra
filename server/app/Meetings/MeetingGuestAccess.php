<?php

namespace App\Meetings;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingGuestSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MeetingGuestAccess
{
    public const GUEST_CAP = 25;

    public function __construct(private readonly MeetingGuestSecurityMonitor $securityMonitor) {}

    public function inspect(string $meetingId, string $token): Meeting
    {
        return $this->resolveLink($meetingId, $token)->load(['organization', 'organizer']);
    }

    /** @return array{session: MeetingGuestSession, created: bool} */
    public function admit(
        string $meetingId,
        string $token,
        string $displayName,
        string $idempotencyKey,
    ): array {
        $normalizedName = preg_replace('/\s+/u', ' ', trim($displayName)) ?? trim($displayName);
        $requestHash = hash('sha256', json_encode([
            'display_name' => $normalizedName,
            'link_token_hash' => hash('sha256', $token),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($meetingId, $token, $normalizedName, $idempotencyKey, $requestHash): array {
            $meeting = $this->resolveLink($meetingId, $token, true);

            $existing = $meeting->guestSessions()
                ->where('admission_idempotency_key', $idempotencyKey)
                ->with(['participant', 'meeting'])
                ->first();
            if ($existing !== null) {
                if (! hash_equals($existing->admission_request_hash, $requestHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This admission key was already used for a different guest.'],
                    ]);
                }

                if ($existing->revoked_at === null && $existing->participant->removed_at === null) {
                    return ['session' => $existing, 'created' => false];
                }

                throw ValidationException::withMessages([
                    'idempotency_key' => ['This guest admission has ended. Reopen the meeting link to request a new session.'],
                ]);
            }

            if ($meeting->status !== MeetingStatus::Live) {
                throw ValidationException::withMessages([
                    'meeting' => [$meeting->status === MeetingStatus::Scheduled
                        ? 'The organizer has not started this meeting yet.'
                        : 'This meeting is no longer open.'],
                ]);
            }

            if ($meeting->participants()->where('kind', 'guest')->whereNull('removed_at')->count() >= self::GUEST_CAP) {
                $this->securityMonitor->record('capacity-rejected');
                throw ValidationException::withMessages([
                    'meeting' => ['This meeting has reached its guest participant limit.'],
                ]);
            }

            $participant = $meeting->participants()->create([
                'kind' => 'guest',
                'guest_admission_source' => 'copied-link',
                'display_name' => $normalizedName,
                'joined_at' => now(),
            ]);
            $sessionToken = Str::random(64);
            $session = $meeting->guestSessions()->create([
                'meeting_participant_id' => $participant->getKey(),
                'token_hash' => hash('sha256', $sessionToken),
                'token' => $sessionToken,
                'admission_idempotency_key' => $idempotencyKey,
                'admission_request_hash' => $requestHash,
                'expires_at' => $meeting->guest_link_expires_at,
                'last_seen_at' => now(),
            ]);

            return [
                'session' => $session->load(['participant', 'meeting']),
                'created' => true,
            ];
        });
    }

    public function resolveSessionToken(?string $token): ?MeetingGuestSession
    {
        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            return null;
        }

        $session = MeetingGuestSession::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->with(['participant', 'meeting.organization', 'meeting.organizer'])
            ->first();

        if (
            $session === null
            || $session->participant->meeting_id !== $session->meeting_id
            || $session->participant->removed_at !== null
        ) {
            return null;
        }

        return $session;
    }

    public function meeting(MeetingGuestSession $session): Meeting
    {
        return $session->meeting->loadMissing([
            'organization',
            'organizer',
            'participants.user',
            'participants.invitation',
            'agendaItems.owner',
            'outcomes.author',
            'outcomes.guestAuthor',
            'outcomes.assignee',
            'outcomes.attentionItem',
        ]);
    }

    private function resolveLink(string $meetingId, string $token, bool $lock = false): Meeting
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            throw (new ModelNotFoundException)->setModel(Meeting::class);
        }

        $query = Meeting::query()
            ->where('public_id', Str::upper($meetingId))
            ->where('guest_link_token_hash', hash('sha256', $token))
            ->whereNull('guest_link_revoked_at')
            ->where('guest_link_expires_at', '>', now())
            ->whereIn('status', [MeetingStatus::Scheduled->value, MeetingStatus::Live->value]);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }
}
