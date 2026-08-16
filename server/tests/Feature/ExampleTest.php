<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertExactJson([
                'name' => 'Katra Server',
                'documentation' => 'https://katra.io/docs/',
            ]);
    }
}
