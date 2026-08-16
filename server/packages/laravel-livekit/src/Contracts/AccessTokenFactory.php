<?php

namespace Katra\LiveKit\Contracts;

use Katra\LiveKit\ParticipantGrant;

interface AccessTokenFactory
{
    public function participant(ParticipantGrant $grant): string;

    public function roomAdmin(string $roomName): string;

    public function roomCreate(): string;
}
