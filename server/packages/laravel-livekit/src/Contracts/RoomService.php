<?php

namespace Katra\LiveKit\Contracts;

interface RoomService
{
    public function removeParticipant(string $roomName, string $participantIdentity): bool;

    public function deleteRoom(string $roomName): bool;
}
