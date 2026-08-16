<?php

namespace App\Meetings;

use App\Models\MeetingInvitation;
use App\Models\User;
use App\Notifications\MeetingInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class MeetingInvitationDelivery
{
    public function queue(MeetingInvitation $invitation, ?User $actor = null, string $eventKind = 'queued'): void
    {
        $invitation = DB::transaction(function () use ($invitation, $actor, $eventKind): MeetingInvitation {
            $locked = MeetingInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());

            if ($locked->revoked_at !== null || $locked->expires_at->isPast()) {
                return $locked;
            }

            $locked->forceFill([
                'send_count' => $locked->send_count + 1,
                'last_queued_at' => now(),
                'last_failed_at' => null,
            ])->save();
            $locked->events()->create([
                'kind' => $eventKind,
                'actor_user_id' => $actor?->getKey(),
            ]);

            return $locked;
        });

        if ($invitation->revoked_at !== null || $invitation->expires_at->isPast()) {
            return;
        }

        try {
            Notification::route('mail', $invitation->email)->notify(
                new MeetingInvitationNotification($invitation, $invitation->token_hash),
            );
        } catch (Throwable $exception) {
            $this->markFailed($invitation, $invitation->token_hash);
            report($exception);
        }
    }

    public function markSent(MeetingInvitation $invitation, string $tokenHash): void
    {
        $this->markDelivery($invitation, $tokenHash, 'sent');
    }

    public function markFailed(MeetingInvitation $invitation, string $tokenHash): void
    {
        $this->markDelivery($invitation, $tokenHash, 'failed');
    }

    private function markDelivery(MeetingInvitation $invitation, string $tokenHash, string $kind): void
    {
        DB::transaction(function () use ($invitation, $tokenHash, $kind): void {
            $locked = MeetingInvitation::query()->lockForUpdate()->find($invitation->getKey());
            if ($locked === null || ! hash_equals($locked->token_hash, $tokenHash)) {
                return;
            }

            $column = $kind === 'sent' ? 'last_sent_at' : 'last_failed_at';
            $locked->forceFill([$column => now()])->save();
            $locked->events()->create(['kind' => $kind]);
        });
    }
}
