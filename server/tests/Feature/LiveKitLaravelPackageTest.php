<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Katra\LiveKit\Contracts\AccessTokenFactory;
use Katra\LiveKit\Contracts\RoomService;
use Katra\LiveKit\Exceptions\RoomServiceRequestFailed;
use Tests\TestCase;

final class LiveKitLaravelPackageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('livekit', [
            'url' => 'http://livekit.test:7880',
            'public_url' => 'ws://livekit.test:7880',
            'api_key' => 'katra-test-key',
            'api_secret' => 'a-development-secret-long-enough-for-testing',
            'join_token_ttl' => 120,
            'service_token_ttl' => 60,
            'http_timeout' => 5,
        ]);
    }

    public function test_laravel_discovers_the_package_contracts(): void
    {
        $this->assertTrue($this->app->bound(AccessTokenFactory::class));
        $this->assertTrue($this->app->bound(RoomService::class));
    }

    public function test_room_service_uses_the_documented_json_twirp_boundary(): void
    {
        Http::fake([
            'http://livekit.test:7880/twirp/livekit.RoomService/RemoveParticipant' => Http::response([], 200),
            'http://livekit.test:7880/twirp/livekit.RoomService/DeleteRoom' => Http::response([], 200),
        ]);

        $rooms = $this->app->make(RoomService::class);

        $this->assertTrue($rooms->removeParticipant('room-generation-01', 'participant-01'));
        $this->assertTrue($rooms->deleteRoom('room-generation-01'));

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://livekit.test:7880/twirp/livekit.RoomService/RemoveParticipant'
                && $request->method() === 'POST'
                && $request['room'] === 'room-generation-01'
                && $request['identity'] === 'participant-01'
                && str_starts_with((string) $request->header('Authorization')[0], 'Bearer ')
                && $request->header('Content-Type')[0] === 'application/json';
        });
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://livekit.test:7880/twirp/livekit.RoomService/DeleteRoom'
                && $request['room'] === 'room-generation-01'
                && str_starts_with((string) $request->header('Authorization')[0], 'Bearer ');
        });
    }

    public function test_room_service_treats_absent_resources_as_idempotent_and_redacts_failures(): void
    {
        Http::fakeSequence()
            ->push(['code' => 'not_found', 'msg' => 'room secret-room was not found'], 404)
            ->push(['code' => 'internal', 'msg' => 'api secret leaked-value'], 500);

        $rooms = $this->app->make(RoomService::class);

        $this->assertFalse($rooms->deleteRoom('missing-room'));

        try {
            $rooms->deleteRoom('secret-room');
            $this->fail('Expected the LiveKit Room Service failure.');
        } catch (RoomServiceRequestFailed $exception) {
            $this->assertSame(500, $exception->status);
            $this->assertSame('internal', $exception->twirpCode);
            $this->assertStringNotContainsString('leaked-value', $exception->getMessage());
            $this->assertStringNotContainsString('secret-room', $exception->getMessage());
        }
    }
}
