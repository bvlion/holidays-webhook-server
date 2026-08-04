<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_未認証の場合はAPIへアクセスできない()
    {
        $response = $this->getJson('/api/commands');

        $response->assertStatus(401);
    }

    public function test_有効なAPIトークンで認証済みの場合はAPIへアクセスできる()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')->getJson('/api/commands');

        $response->assertStatus(200);
    }

    public function test_APIトークンをクエリパラメータで渡しても認証できる()
    {
        $user = User::factory()->create();

        $response = $this->getJson('/api/commands?api_token=' . $user->api_token);

        $response->assertStatus(200);
    }
}
