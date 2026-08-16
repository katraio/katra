<?php

namespace App\Listeners;

use App\Meetings\MeetingInvitationDelivery;
use App\Notifications\MeetingInvitationNotification;
use Illuminate\Notifications\Events\NotificationSent;

final class MarkMeetingInvitationSent
{
    public function __construct(private readonly MeetingInvitationDelivery $delivery) {}

    public function handle(NotificationSent $event): void
    {
        if (! $event->notification instanceof MeetingInvitationNotification || $event->channel !== 'mail') {
            return;
        }

        $this->delivery->markSent(
            $event->notification->invitation,
            $event->notification->tokenHash,
        );
    }
}
