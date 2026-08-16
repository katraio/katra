<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case Administrator = 'organization-administrator';
    case InternalMember = 'internal-member';
    case ClientAdministrator = 'client-administrator';
    case ClientMember = 'client-member';

    public function membershipKind(): MembershipKind
    {
        return match ($this) {
            self::Administrator, self::InternalMember => MembershipKind::Internal,
            self::ClientAdministrator, self::ClientMember => MembershipKind::Client,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Organization administrator',
            self::InternalMember => 'Internal member',
            self::ClientAdministrator => 'Client administrator',
            self::ClientMember => 'Client member',
        };
    }
}
