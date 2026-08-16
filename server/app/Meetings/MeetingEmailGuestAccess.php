<?php

namespace App\Meetings;

use App\Enums\MeetingStatus;
use App\Events\MeetingParticipantAccessChanged;
use App\Models\Meeting;
use App\Models\MeetingGuestSession;
use App\Models\MeetingInvitation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MeetingEmailGuestAccess
{
    public function __construct(private readonly MeetingGuestSecurityMonitor $securityMonitor) {}

    public function inspect(string $invitationId, string $token): MeetingInvitation
    {
        return $this->resolveInvitation($invitationId, $token)
            ->load(['meeting.organization', 'meeting.organizer']);
    }

    /** @return array{session: MeetingGuestSession, created: bool} */
    public function admit(string $invitationId, string $token, string $displayName, string $idempotencyKey): array
    {
        $normalizedName = preg_replace('/\s+/u', ' ', trim($displayName)) ?? trim($displayName);
        $requestHash = hash('sha256', json_encode([
            'display_name' => $normalizedName,
            'invitation_token_hash' => hash('sha256', $token),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($invitationId, $token, $normalizedName, $idempotencyKey, $requestHash): array {
            $invitation = $this->resolveInvitation($invitationId, $token, true);
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($invitation->meeting_id);
            if ($meeting->status !== MeetingStatus::Live) {
                throw ValidationException::withMessages([
                    'meeting' => [$meeting->status === MeetingStatus::Scheduled
                        ? 'The organizer has not started this meeting yet.'
                        : 'This meeting is no longer open.'],
                ]);
            }

            $participant = $meeting->participants()
                ->where('meeting_invitation_id', $invitation->getKey())
                ->lockForUpdate()
                ->first();
            $session = $participant?->guestSession()->lockForUpdate()->first();

            if (
                $session !== null
                && $session->revoked_at === null
                && $participant?->removed_at === null
                && hash_equals($session->admission_idempotency_key, $idempotencyKey)
            ) {
                if (! hash_equals($session->admission_request_hash, $requestHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This admission key was already used for a different guest.'],
                    ]);
                }

                return ['session' => $session->load(['participant', 'meeting']), 'created' => false];
            }

            if ($participant === null) {
                if ($meeting->participants()->where('kind', 'guest')->whereNull('removed_at')->count() >= MeetingGuestAccess::GUEST_CAP) {
                    $this->securityMonitor->record('capacity-rejected');
                    throw ValidationException::withMessages([
                        'meeting' => ['This meeting has reached its guest participant limit.'],
                    ]);
                }
                $participant = $meeting->participants()->create([
                    'meeting_invitation_id' => $invitation->getKey(),
                    'kind' => 'guest',
                    'guest_admission_source' => 'email-invitation',
                    'display_name' => $normalizedName,
                    'joined_at' => now(),
                ]);
            } else {
                $wasRemoved = $participant->removed_at !== null;
                $participant->forceFill([
                    'display_name' => $normalizedName,
                    'left_at' => null,
                    'removed_by_user_id' => null,
                    'removed_at' => null,
                ])->save();
                if ($wasRemoved) {
                    $participant->events()->create([
                        'meeting_id' => $meeting->getKey(),
                        'kind' => 'restored',
                    ]);
                    MeetingParticipantAccessChanged::dispatch($meeting, $participant, 'restored');
                    DB::afterCommit(fn () => $this->securityMonitor->record('participant-restored'));
                }
            }

            $sessionToken = Str::random(64);
            $sessionValues = [
                'meeting_id' => $meeting->getKey(),
                'token_hash' => hash('sha256', $sessionToken),
                'token' => $sessionToken,
                'admission_idempotency_key' => $idempotencyKey,
                'admission_request_hash' => $requestHash,
                'expires_at' => $invitation->expires_at,
                'revoked_at' => null,
                'last_seen_at' => now(),
            ];
            if ($session === null) {
                $session = $participant->guestSession()->create($sessionValues);
            } else {
                $session->forceFill($sessionValues)->save();
            }

            if ($invitation->admitted_at === null) {
                $invitation->forceFill(['admitted_at' => now()])->save();
                $invitation->events()->create(['kind' => 'admitted']);
            }

            return ['session' => $session->load(['participant', 'meeting']), 'created' => true];
        });
    }

    public function meeting(MeetingGuestSession $session): Meeting
    {
        return app(MeetingGuestAccess::class)->meeting($session);
    }

    private function resolveInvitation(string $invitationId, string $token, bool $lock = false): MeetingInvitation
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            throw (new ModelNotFoundException)->setModel(MeetingInvitation::class);
        }

        $query = MeetingInvitation::query()
            ->where('public_id', Str::upper($invitationId))
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->whereHas('meeting', fn ($meeting) => $meeting->whereIn('status', [
                MeetingStatus::Scheduled->value,
                MeetingStatus::Live->value,
            ]));

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }
}
