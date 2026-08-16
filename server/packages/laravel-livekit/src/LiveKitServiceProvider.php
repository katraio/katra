<?php

namespace Katra\LiveKit;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Katra\LiveKit\Contracts\AccessTokenFactory;
use Katra\LiveKit\Contracts\Clock;
use Katra\LiveKit\Contracts\RoomService;

final class LiveKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/livekit.php', 'livekit');

        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(AccessTokenFactory::class, function (Application $app): JwtAccessTokenFactory {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('livekit', []);

            return new JwtAccessTokenFactory(
                (string) ($config['api_key'] ?? ''),
                (string) ($config['api_secret'] ?? ''),
                (int) ($config['join_token_ttl'] ?? 120),
                (int) ($config['service_token_ttl'] ?? 60),
                $app->make(Clock::class),
            );
        });
        $this->app->singleton(RoomService::class, function (Application $app): HttpRoomService {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('livekit', []);

            return new HttpRoomService(
                $app->make(Factory::class),
                $app->make(AccessTokenFactory::class),
                (string) ($config['url'] ?? ''),
                (int) ($config['http_timeout'] ?? 5),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/livekit.php' => config_path('livekit.php'),
        ], 'livekit-config');
    }
}
