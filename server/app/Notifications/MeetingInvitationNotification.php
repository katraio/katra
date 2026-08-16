<?php

namespace App\Notifications;

use App\Meetings\MeetingInvitationDelivery;
use App\Models\MeetingInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

final class MeetingInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly MeetingInvitation $invitation,
        public readonly string $tokenHash,
    ) {
        $this->afterCommit();
        $this->onQueue('mail');
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $invitation = $this->invitation->fresh('meeting');

        if (
            $invitation === null
            || ! hash_equals($invitation->token_hash, $this->tokenHash)
            || $invitation->revoked_at !== null
            || $invitation->expires_at->isPast()
            || ! in_array($invitation->meeting->status->value, ['scheduled', 'live'], true)
        ) {
            return [];
        }

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = $this->invitation->loadMissing(['meeting.organization', 'meeting.organizer']);
        $meeting = $invitation->meeting;
        $startsAt = $meeting->starts_at->utc()->format('M j, Y \a\t g:i A T');

        return (new MailMessage)
            ->subject("Meeting invitation: {$meeting->title}")
            ->greeting("You're invited to a Katra meeting")
            ->action('Open meeting', $this->invitationUrl($invitation))
            ->markdown('mail.meeting-invitation', [
                'duration' => $meeting->duration_minutes,
                'meetingTitle' => $meeting->title,
                'organizationName' => $meeting->organization->name,
                'organizerName' => $meeting->organizer->name,
                'startsAt' => $startsAt,
            ])
            ->theme('katra');
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'meeting-invitation:'.$this->invitation->public_id,
            'meeting:'.$this->invitation->meeting->public_id,
        ];
    }

    public function failed(Throwable $exception): void
    {
        app(MeetingInvitationDelivery::class)->markFailed($this->invitation, $this->tokenHash);
    }

    private function invitationUrl(MeetingInvitation $invitation): string
    {
        return sprintf(
            '%s/meeting-invitations/%s#token=%s',
            rtrim((string) config('app.client_url'), '/'),
            $invitation->public_id,
            rawurlencode($invitation->token),
        );
    }
}
