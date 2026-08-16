<?php

namespace App\Meetings;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MeetingInvitationService
{
    public function __construct(private readonly MeetingInvitationDelivery $delivery) {}

    /** @param list<string> $emails */
    public function add(Meeting $meeting, User $actor, array $emails): Meeting
    {
        $this->assertOrganizer($meeting, $actor);
        $normalized = collect($emails)->map(fn (string $email): string => Str::lower(trim($email)))->unique()->values();

        /** @var Collection<int, array{invitation: MeetingInvitation, event: string}> $queued */
        $queued = DB::transaction(function () use ($meeting, $actor, $normalized): Collection {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->getKey());
            $this->assertOpen($locked);

            $existingCount = $locked->invitations()->count();
            $newCount = $normalized->filter(
                fn (string $email): bool => ! $locked->invitations()->where('email', $email)->exists(),
            )->count();
            if ($existingCount + $newCount > MeetingGuestAccess::GUEST_CAP) {
                throw ValidationException::withMessages([
                    'guest_emails' => ['A meeting can have at most 25 external email invitations.'],
                ]);
            }

            return $normalized->map(function (string $email) use ($locked, $actor): array {
                $invitation = $locked->invitations()->where('email', $email)->lockForUpdate()->first();
                if ($invitation === null) {
                    $token = Str::random(64);
                    $invitation = $locked->invitations()->create([
                        'email' => $email,
                        'token_hash' => hash('sha256', $token),
                        'token' => $token,
                        'expires_at' => $locked->guest_link_expires_at,
                        'created_by_user_id' => $actor->getKey(),
                    ]);

                    return ['invitation' => $invitation, 'event' => 'queued'];
                }

                if ($invitation->revoked_at !== null) {
                    throw ValidationException::withMessages([
                        'guest_emails' => ["{$email} was revoked. Use resend to issue a new invitation."],
                    ]);
                }

                return ['invitation' => $invitation, 'event' => 'queued'];
            });
        });

        $queued->each(fn (array $item) => $this->delivery->queue($item['invitation'], $actor, $item['event']));

        return $this->loaded($meeting);
    }

    public function resend(Meeting $meeting, MeetingInvitation $invitation, User $actor): Meeting
    {
        $this->assertOrganizer($meeting, $actor);
        $rotated = DB::transaction(function () use ($meeting, $invitation): MeetingInvitation {
            $lockedMeeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->getKey());
            $this->assertOpen($lockedMeeting);
            $locked = $lockedMeeting->invitations()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            $token = Str::random(64);
            $locked->forceFill([
                'token_hash' => hash('sha256', $token),
                'token' => $token,
                'expires_at' => $lockedMeeting->guest_link_expires_at,
                'revoked_at' => null,
                'last_failed_at' => null,
            ])->save();

            return $locked;
        });

        $this->delivery->queue($rotated, $actor, 'resent');

        return $this->loaded($meeting);
    }

    public function revoke(Meeting $meeting, MeetingInvitation $invitation, User $actor): Meeting
    {
        $this->assertOrganizer($meeting, $actor);
        DB::transaction(function () use ($meeting, $invitation, $actor): void {
            $lockedMeeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->getKey());
            $locked = $lockedMeeting->invitations()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null) {
                return;
            }
            $locked->forceFill(['revoked_at' => now()])->save();
            $locked->events()->create([
                'kind' => 'revoked',
                'actor_user_id' => $actor->getKey(),
            ]);
        });

        return $this->loaded($meeting);
    }

    public function queueCreated(Meeting $meeting, User $actor): void
    {
        $meeting->loadMissing('invitations');
        $meeting->invitations->each(fn (MeetingInvitation $invitation) => $this->delivery->queue($invitation, $actor));
    }

    private function assertOrganizer(Meeting $meeting, User $actor): void
    {
        if ($meeting->organizer_user_id !== $actor->getKey()) {
            throw new AuthorizationException;
        }
    }

    private function assertOpen(Meeting $meeting): void
    {
        if (! in_array($meeting->status, [MeetingStatus::Scheduled, MeetingStatus::Live], true)) {
            throw ValidationException::withMessages([
                'meeting' => ['Invitations can be sent only while a meeting is scheduled or live.'],
            ]);
        }
        if ($meeting->guest_link_expires_at->isPast()) {
            throw ValidationException::withMessages([
                'meeting' => ['This meeting invitation window has expired.'],
            ]);
        }
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
