<?php

namespace Katra\LiveKit\Contracts;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
