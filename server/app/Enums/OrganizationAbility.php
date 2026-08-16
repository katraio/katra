<?php

namespace App\Enums;

enum OrganizationAbility: string
{
    case View = 'organization.view';
    case ManageMembers = 'organization.members.manage';
    case InviteInternalMembers = 'organization.invitations.internal.create';
    case InviteClientAdministrators = 'organization.invitations.client-administrator.create';
    case InviteClientMembers = 'organization.invitations.client-member.create';
    case ApproveAccessRequests = 'organization.access-requests.approve';
    case CreateInternalChannels = 'organization.channels.create';
    case ManageChannels = 'organization.channels.manage';
    case CreateDirectMessages = 'organization.direct-messages.create';
    case CreateMeetings = 'organization.meetings.create';
}
