<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

final class SystemConnectionTest extends TestCase
{
    /**
     * Verify the initial Katra Client-to-Server connection contract.
     */
    public function test_client_can_confirm_the_server_connection(): void
    {
        $this->getJson('/api/v1/system/connection')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'status' => 'connected',
                    'application' => 'Katra Server',
                    'api_version' => 'v1',
                ],
            ]);
    }
}
