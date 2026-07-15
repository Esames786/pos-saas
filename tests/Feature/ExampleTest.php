<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_health_endpoint_is_available(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }
}
