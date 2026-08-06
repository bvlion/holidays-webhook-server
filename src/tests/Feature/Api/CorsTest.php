<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_apiへのpreflightリクエストにcorsヘッダーが付与される()
    {
        $response = $this->withHeaders([
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/commands');

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_未認証のapiリクエストにもcorsヘッダーが付与される()
    {
        $response = $this->withHeaders([
            'Origin' => 'https://example.com',
        ])->getJson('/api/commands');

        $response->assertStatus(401);
        $response->assertHeader('Access-Control-Allow-Origin', '*');
    }
}
