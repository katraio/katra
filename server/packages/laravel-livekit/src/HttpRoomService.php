<?php

namespace Katra\LiveKit;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Katra\LiveKit\Contracts\AccessTokenFactory;
use Katra\LiveKit\Contracts\RoomService;
use Katra\LiveKit\Exceptions\InvalidConfiguration;
use Katra\LiveKit\Exceptions\RoomServiceRequestFailed;

final readonly class HttpRoomService implements RoomService
{
    public function __construct(
        private Factory $http,
        private AccessTokenFactory $tokens,
        private string $baseUrl,
        private int $timeout,
    ) {
        if (! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw InvalidConfiguration::invalid('url');
        }
        if ($timeout < 1 || $timeout > 30) {
            throw InvalidConfiguration::invalid('http_timeout');
        }
    }

    public function removeParticipant(string $roomName, string $participantIdentity): bool
    {
        if (trim($participantIdentity) === '') {
            throw new \InvalidArgumentException('A LiveKit participant identity is required.');
        }

        return $this->request(
            'RemoveParticipant',
            ['room' => $roomName, 'identity' => $participantIdentity],
            $this->tokens->roomAdmin($roomName),
        );
    }

    public function deleteRoom(string $roomName): bool
    {
        return $this->request(
            'DeleteRoom',
            ['room' => $roomName],
            $this->tokens->roomCreate(),
        );
    }

    /** @param array<string, mixed> $payload */
    private function request(string $method, array $payload, string $token): bool
    {
        $response = $this->http
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->withToken($token)
            ->timeout($this->timeout)
            ->post("/twirp/livekit.RoomService/{$method}", $payload);

        if ($response->successful()) {
            return true;
        }
        if ($response->notFound()) {
            return false;
        }

        throw $this->failure($response);
    }

    private function failure(Response $response): RoomServiceRequestFailed
    {
        $code = $response->json('code');

        return new RoomServiceRequestFailed(
            $response->status(),
            is_string($code) && preg_match('/^[a-z_]+$/', $code) ? $code : null,
        );
    }
}
