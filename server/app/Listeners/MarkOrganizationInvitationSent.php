<?php

namespace App\Listeners;

use App\Notifications\OrganizationInvitationNotification;
use App\Organizations\OrganizationInvitationDelivery;
use Illuminate\Notifications\Events\NotificationSent;

final class MarkOrganizationInvitationSent
{
    public function __construct(private readonly OrganizationInvitationDelivery $delivery) {}

    public function handle(NotificationSent $event): void
    {
        if (! $event->notification instanceof OrganizationInvitationNotification || $event->channel !== 'mail') {
            return;
        }

        $this->delivery->markSent(
            $event->notification->invitation,
            $event->notification->tokenHash,
        );
    }
}
