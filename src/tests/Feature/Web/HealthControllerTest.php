<?php

namespace Tests\Feature\Web;

use App\Libs\SchedulerHeartbeat;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HealthControllerTest extends TestCase
{
    private function heartbeat(): SchedulerHeartbeat
    {
        return app(SchedulerHeartbeat::class);
    }

    private function setValidConfig(): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.connections.mysql.host' => 'db',
            'database.connections.mysql.database' => 'hw',
            'database.connections.mysql.username' => 'user',
            'database.connections.mysql.password' => 'password',
            'services.google.client_id' => 'test-google-client-id',
            'services.google.client_secret' => 'test-google-client-secret',
            'services.google.redirect' => 'http://localhost:8000/login/callback',
            'services.google_calendar.api_key' => 'test-google-calendar-api-key',
        ]);
    }

    protected function tearDown(): void
    {
        $path = $this->heartbeat()->path();
        @unlink($path);
        @unlink($path.'.lock');

        parent::tearDown();
    }

    public function test_すべての条件を満たすとhttp200を返す()
    {
        $this->setValidConfig();
        $this->heartbeat()->recordSuccess('time:trigger');

        $response = $this->getJson('/health');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'ok',
            'components' => [
                'app' => 'ok',
                'database' => 'ok',
                'config' => 'ok',
                'scheduler' => 'ok',
            ],
        ]);
    }

    public function test_db接続に失敗するとhttp503を返す()
    {
        $this->setValidConfig();
        $this->heartbeat()->recordSuccess('time:trigger');
        DB::shouldReceive('select')->once()->andThrow(new RuntimeException('connection refused'));

        $response = $this->getJson('/health');

        $response->assertStatus(503);
        $this->assertSame('ng', $response->json('components.database'));
        $this->assertSame('ng', $response->json('status'));
    }

    public function test_必須設定が不足しているとhttp503を返す()
    {
        $this->setValidConfig();
        config(['services.google_calendar.api_key' => '']);
        $this->heartbeat()->recordSuccess('time:trigger');

        $response = $this->getJson('/health');

        $response->assertStatus(503);
        $this->assertSame('ng', $response->json('components.config'));
    }

    public function test_heartbeatが新しい場合はschedulerが正常判定になる()
    {
        $this->setValidConfig();
        $this->heartbeat()->recordSuccess('time:trigger');

        $response = $this->getJson('/health');

        $response->assertStatus(200);
        $this->assertSame('ok', $response->json('components.scheduler'));
    }

    public function test_heartbeatが古い場合はschedulerが異常判定になりhttp503を返す()
    {
        $this->setValidConfig();
        file_put_contents($this->heartbeat()->path(), json_encode([
            'time:trigger' => [
                'status' => 'success',
                'last_success_at' => now('UTC')->subHours(1)->toIso8601String(),
            ],
        ]));

        $response = $this->getJson('/health');

        $response->assertStatus(503);
        $this->assertSame('ng', $response->json('components.scheduler'));
    }

    public function test_heartbeatが存在しない場合はschedulerが異常判定になりhttp503を返す()
    {
        $this->setValidConfig();
        @unlink($this->heartbeat()->path());

        $response = $this->getJson('/health');

        $response->assertStatus(503);
        $this->assertSame('ng', $response->json('components.scheduler'));
    }

    public function test_scheduler失敗の記録は成功時刻として扱われずhttp503を返す()
    {
        $this->setValidConfig();
        $this->heartbeat()->recordFailure('time:trigger');

        $response = $this->getJson('/health');

        $response->assertStatus(503);
        $this->assertSame('ng', $response->json('components.scheduler'));
    }

    public function test_レスポンスに秘密情報や内部パスが含まれない()
    {
        $this->setValidConfig();
        config(['database.connections.mysql.password' => 'super-secret-password']);
        $this->heartbeat()->recordSuccess('time:trigger');

        $response = $this->getJson('/health');
        $response->assertJsonStructure(['status', 'components' => ['app', 'database', 'config', 'scheduler']]);

        $body = $response->getContent();
        $this->assertStringNotContainsString('super-secret-password', $body);
        $this->assertStringNotContainsString('test-google-calendar-api-key', $body);
        $this->assertStringNotContainsString('test-google-client-secret', $body);
        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertStringNotContainsString(gethostname(), $body);
    }
}
