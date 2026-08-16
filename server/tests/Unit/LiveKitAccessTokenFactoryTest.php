<?php

namespace Tests\Unit;

use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Katra\LiveKit\Contracts\Clock;
use Katra\LiveKit\JwtAccessTokenFactory;
use Katra\LiveKit\ParticipantGrant;
use PHPUnit\Framework\TestCase;

final class LiveKitAccessTokenFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        JWT::$timestamp = null;

        parent::tearDown();
    }

    public function test_it_creates_an_exact_room_participant_grant(): void
    {
        $secret = 'a-development-secret-long-enough-for-testing';
        $factory = new JwtAccessTokenFactory(
            'katra-test-key',
            $secret,
            120,
            60,
            new class implements Clock
            {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-08-11T12:00:00+00:00');
                }
            },
        );

        JWT::$timestamp = 1786449600;
        $token = $factory->participant(new ParticipantGrant(
            'room-generation-01',
            'participant-01',
            [ParticipantGrant::MICROPHONE, ParticipantGrant::CAMERA],
        ));
        $claims = (array) JWT::decode($token, new Key($secret, 'HS256'));
        $video = (array) $claims['video'];

        $this->assertSame('katra-test-key', $claims['iss']);
        $this->assertSame('participant-01', $claims['sub']);
        $this->assertSame(1786449600, $claims['nbf']);
        $this->assertSame(1786449720, $claims['exp']);
        $this->assertSame('room-generation-01', $video['room']);
        $this->assertTrue($video['roomJoin']);
        $this->assertTrue($video['canPublish']);
        $this->assertTrue($video['canSubscribe']);
        $this->assertFalse($video['canPublishData']);
        $this->assertSame(['microphone', 'camera'], $video['canPublishSources']);
        $this->assertArrayNotHasKey('roomAdmin', $video);
        $this->assertArrayNotHasKey('roomCreate', $video);
    }

    public function test_room_service_grants_do_not_receive_participant_permissions(): void
    {
        $secret = 'a-development-secret-long-enough-for-testing';
        $clock = new class implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-11T12:00:00+00:00');
            }
        };
        $factory = new JwtAccessTokenFactory('katra-test-key', $secret, 120, 60, $clock);

        JWT::$timestamp = 1786449600;
        $admin = (array) JWT::decode($factory->roomAdmin('room-generation-01'), new Key($secret, 'HS256'));
        $adminVideo = (array) $admin['video'];
        $create = (array) JWT::decode($factory->roomCreate(), new Key($secret, 'HS256'));
        $createVideo = (array) $create['video'];

        $this->assertSame(['roomAdmin' => true, 'room' => 'room-generation-01'], $adminVideo);
        $this->assertSame(['roomCreate' => true], $createVideo);
        $this->assertArrayNotHasKey('sub', $admin);
        $this->assertArrayNotHasKey('sub', $create);
    }
}
