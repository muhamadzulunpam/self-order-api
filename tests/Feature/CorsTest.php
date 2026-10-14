<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cors.allowed_origins' => ['http://localhost:5173']]);
    }

    public function test_allowed_origin_receives_cors_header(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->getJson('/api/public/tables/token-apa-saja/menu')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_unknown_origin_is_not_granted_access(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://situs-lain.example'])
            ->getJson('/api/public/tables/token-apa-saja/menu');

        $allowOrigin = $response->headers->get('Access-Control-Allow-Origin');

        $this->assertNotSame('*', $allowOrigin);
        $this->assertNotSame('https://situs-lain.example', $allowOrigin);
    }

    public function test_preflight_request_is_answered(): void
    {
        $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
