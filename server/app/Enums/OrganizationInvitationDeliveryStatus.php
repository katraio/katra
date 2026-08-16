<?php

namespace App\Enums;

enum OrganizationInvitationDeliveryStatus: string
{
    case CopyLinkOnly = 'copy-link-only';
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
}
