<?php

namespace App\Enums;

enum OrganizationInvitationEventKind: string
{
    case Issued = 'issued';
    case Reissued = 'reissued';
    case Superseded = 'superseded';
    case DeliverySkipped = 'delivery-skipped';
    case DeliveryQueued = 'delivery-queued';
    case DeliverySent = 'delivery-sent';
    case DeliveryFailed = 'delivery-failed';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
}
