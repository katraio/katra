<?php

namespace App\Enums;

enum MeetingOutcomeKind: string
{
    case Note = 'note';
    case Decision = 'decision';
    case Action = 'action';
}
