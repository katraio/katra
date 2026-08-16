<?php

namespace App\Enums;

enum UserAccountEventKind: string
{
    case ProfileUpdated = 'profile-updated';
    case PasswordChanged = 'password-changed';
}
