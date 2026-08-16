<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Katra\LiveKit\Contracts\RoomService;
use Throwable;

final class RetireMeetingMediaRoom implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [1, 5, 15, 30];

    public function __construct(
        public readonly string $roomName,
        public readonly ?string $participantIdentity = null,
    ) {}

    public function handle(RoomService $rooms): void
    {
        if ($this->participantIdentity !== null) {
            try {
                $rooms->removeParticipant($this->roomName, $this->participantIdentity);
            } catch (Throwable) {
                // Deleting the rotated room below is the authoritative revocation step.
            }
        }

        $rooms->deleteRoom($this->roomName);
    }
}
