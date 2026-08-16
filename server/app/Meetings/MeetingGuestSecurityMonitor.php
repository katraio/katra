<?php

namespace App\Meetings;

use Laravel\Pulse\Facades\Pulse;
use LogicException;

final class MeetingGuestSecurityMonitor
{
    private const ALLOWED_KINDS = [
        'inspection-rejected',
        'admission-rejected',
        'capacity-rejected',
        'participant-removed',
        'session-revoked',
        'invitation-blocked',
        'participant-restored',
    ];

    public function record(string $kind): void
    {
        if (! in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new LogicException('Unsupported meeting guest security metric.');
        }

        Pulse::record('meeting_guest_security', $kind)->count()->onlyBuckets();
    }
}
