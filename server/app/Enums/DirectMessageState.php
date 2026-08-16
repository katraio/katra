<?php

namespace App\Enums;

enum DirectMessageState: string
{
    case Open = 'open';
    case Completed = 'completed';
    case ContinuationRequested = 'continuation-requested';
}
