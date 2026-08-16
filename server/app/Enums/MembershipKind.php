<?php

namespace App\Enums;

enum MembershipKind: string
{
    case Internal = 'internal';
    case Client = 'client';
}
