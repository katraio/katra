<?php

namespace Katra\LiveKit\Exceptions;

use RuntimeException;

final class InvalidConfiguration extends RuntimeException
{
    public static function missing(string $key): self
    {
        return new self("LiveKit configuration [{$key}] is required.");
    }

    public static function invalid(string $key): self
    {
        return new self("LiveKit configuration [{$key}] is invalid.");
    }
}
