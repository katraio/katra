<?php

namespace Katra\LiveKit\Exceptions;

use RuntimeException;

final class RoomServiceRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $twirpCode = null,
    ) {
        $suffix = $twirpCode === null ? '' : " ({$twirpCode})";

        parent::__construct("LiveKit Room Service request failed with status {$status}{$suffix}.");
    }
}
