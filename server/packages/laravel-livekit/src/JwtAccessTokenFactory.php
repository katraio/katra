<?php

namespace Katra\LiveKit;

use Firebase\JWT\JWT;
use Katra\LiveKit\Contracts\AccessTokenFactory;
use Katra\LiveKit\Contracts\Clock;
use Katra\LiveKit\Exceptions\InvalidConfiguration;

final readonly class JwtAccessTokenFactory implements AccessTokenFactory
{
    public function __construct(
        private string $apiKey,
        private string $apiSecret,
        private int $joinTokenTtl,
        private int $serviceTokenTtl,
        private Clock $clock,
    ) {
        if (trim($apiKey) === '') {
            throw InvalidConfiguration::missing('api_key');
        }
        if (trim($apiSecret) === '') {
            throw InvalidConfiguration::missing('api_secret');
        }
        if ($joinTokenTtl < 30 || $joinTokenTtl > 600) {
            throw InvalidConfiguration::invalid('join_token_ttl');
        }
        if ($serviceTokenTtl < 15 || $serviceTokenTtl > 300) {
            throw InvalidConfiguration::invalid('service_token_ttl');
        }
    }

    public function participant(ParticipantGrant $grant): string
    {
        return $this->encode([
            'sub' => $grant->participantIdentity,
            'video' => [
                'roomJoin' => true,
                'room' => $grant->roomName,
                'canPublish' => $grant->publishSources !== [],
                'canSubscribe' => $grant->canSubscribe,
                'canPublishData' => false,
                'canPublishSources' => array_values($grant->publishSources),
            ],
        ], $this->joinTokenTtl);
    }

    public function roomAdmin(string $roomName): string
    {
        if (trim($roomName) === '') {
            throw new \InvalidArgumentException('A LiveKit room name is required.');
        }

        return $this->encode([
            'video' => [
                'roomAdmin' => true,
                'room' => $roomName,
            ],
        ], $this->serviceTokenTtl);
    }

    public function roomCreate(): string
    {
        return $this->encode([
            'video' => ['roomCreate' => true],
        ], $this->serviceTokenTtl);
    }

    /** @param array<string, mixed> $claims */
    private function encode(array $claims, int $ttl): string
    {
        $now = $this->clock->now()->getTimestamp();

        return JWT::encode([
            'iss' => $this->apiKey,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            ...$claims,
        ], $this->apiSecret, 'HS256');
    }
}
