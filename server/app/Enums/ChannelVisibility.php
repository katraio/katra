<?php

namespace App\Enums;

enum ChannelVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case ClientTeam = 'client-team';
}
