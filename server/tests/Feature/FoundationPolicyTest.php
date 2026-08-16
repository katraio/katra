<?php

namespace Tests\Feature;

use Tests\TestCase;

final class FoundationPolicyTest extends TestCase
{
    public function test_the_test_suite_uses_the_isolated_postgres_database(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('katra_testing', config('database.connections.pgsql.database'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('sync', config('queue.default'));
    }

    public function test_fortify_does_not_own_the_client_page_routes(): void
    {
        $this->post('/login')->assertNotFound();
        $this->get('/auth/login')->assertMethodNotAllowed();
    }
}
