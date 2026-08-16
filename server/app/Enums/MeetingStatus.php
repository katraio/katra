<?php

namespace App\Enums;

enum MeetingStatus: string
{
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
