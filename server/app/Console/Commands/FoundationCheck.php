<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

class FoundationCheck extends Command
{
    protected $signature = 'katra:foundation:check {--json : Emit machine-readable JSON}';

    protected $description = 'Verify Katra Server connections to its self-hosted foundation services';

    public function handle(): int
    {
        $checks = [
            'postgresql' => fn (): bool => (int) DB::scalar('select 1') === 1,
            'redis' => fn (): bool => in_array(Redis::ping(), [true, 'PONG'], true),
            'cache' => fn (): bool => $this->verifyCache(),
            'queue' => fn (): bool => config('queue.default') === 'redis',
            'realtime' => fn (): bool => config('broadcasting.default') === 'reverb',
            'meilisearch' => fn (): bool => Http::timeout(3)
                ->get(rtrim((string) config('scout.meilisearch.host'), '/').'/health')
                ->throw()
                ->json('status') === 'available',
        ];

        $results = [];

        foreach ($checks as $name => $check) {
            try {
                $results[$name] = ['status' => $check() ? 'healthy' : 'unhealthy'];
            } catch (Throwable $exception) {
                $results[$name] = [
                    'status' => 'unhealthy',
                    'error' => $exception::class,
                ];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Service', 'Status'],
                collect($results)
                    ->map(fn (array $result, string $name): array => [$name, $result['status']])
                    ->values()
                    ->all(),
            );
        }

        return collect($results)->every(
            fn (array $result): bool => $result['status'] === 'healthy',
        ) ? self::SUCCESS : self::FAILURE;
    }

    private function verifyCache(): bool
    {
        $key = 'katra:foundation-check';

        Cache::put($key, 'healthy', 10);
        $healthy = Cache::get($key) === 'healthy';
        Cache::forget($key);

        return $healthy;
    }
}
