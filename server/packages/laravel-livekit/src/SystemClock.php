<?php

namespace Katra\LiveKit;

use DateTimeImmutable;
use Katra\LiveKit\Contracts\Clock;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }
}
