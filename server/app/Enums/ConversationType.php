<?php

namespace App\Enums;

enum ConversationType: string
{
    case Channel = 'channel';
    case DirectMessage = 'direct-message';
}
