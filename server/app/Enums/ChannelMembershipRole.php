<?php

namespace App\Enums;

enum ChannelMembershipRole: string
{
    case Owner = 'owner';
    case Member = 'member';
}
