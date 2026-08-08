<?php

namespace Tests\Unit\Libs;

use App\Libs\SchedulerHeartbeat;
use Tests\TestCase;

class SchedulerHeartbeatTest extends TestCase
{
    private function heartbeat(): SchedulerHeartbeat
    {
        return new SchedulerHeartbeat;
    }

    protected function tearDown(): void
    {
        $path = $this->heartbeat()->path();
        @unlink($path);
        @unlink($path.'.lock');

        parent::tearDown();
    }

    public function test_成功を記録するとlast_success_atが更新される()
    {
        $heartbeat = $this->heartbeat();

        $heartbeat->recordSuccess('time:trigger');

        $status = $heartbeat->status('time:trigger');
        $this->assertSame('success', $status['status']);
        $this->assertNotEmpty($status['last_success_at']);
    }

    public function test_失敗を記録してもlast_success_atは記録されない()
    {
        $heartbeat = $this->heartbeat();

        $heartbeat->recordFailure('time:trigger');

        $status = $heartbeat->status('time:trigger');
        $this->assertSame('failure', $status['status']);
        $this->assertArrayNotHasKey('last_success_at', $status);
        $this->assertFalse($heartbeat->isFresh('time:trigger', 300));
    }

    public function test_成功後に失敗してもlast_success_atは保持されたままstatusがfailureになる()
    {
        $heartbeat = $this->heartbeat();
        $heartbeat->recordSuccess('time:trigger');
        $successAt = $heartbeat->status('time:trigger')['last_success_at'];

        $heartbeat->recordFailure('time:trigger');

        $status = $heartbeat->status('time:trigger');
        $this->assertSame('failure', $status['status']);
        $this->assertSame($successAt, $status['last_success_at']);
        $this->assertArrayHasKey('last_failure_at', $status);
    }

    public function test_閾値内ならis_freshはtrueになる()
    {
        $heartbeat = $this->heartbeat();
        $heartbeat->recordSuccess('time:trigger');

        $this->assertTrue($heartbeat->isFresh('time:trigger', 60));
    }

    public function test_記録が存在しない場合is_freshはfalseになる()
    {
        $heartbeat = $this->heartbeat();

        $this->assertFalse($heartbeat->isFresh('time:trigger', 300));
    }

    public function test_閾値を超えるとis_freshはfalseになる()
    {
        $heartbeat = $this->heartbeat();
        file_put_contents($heartbeat->path(), json_encode([
            'time:trigger' => [
                'status' => 'success',
                'last_success_at' => now('UTC')->subSeconds(120)->toIso8601String(),
            ],
        ]));

        $this->assertFalse($heartbeat->isFresh('time:trigger', 60));
    }

    public function test_タイムゾーンが異なる時刻表現でも正しく比較される()
    {
        $heartbeat = $this->heartbeat();
        // +09:00オフセット付きの時刻をUTC比較しても新しいと判定される
        file_put_contents($heartbeat->path(), json_encode([
            'time:trigger' => [
                'status' => 'success',
                'last_success_at' => now('Asia/Tokyo')->toIso8601String(),
            ],
        ]));

        $this->assertTrue($heartbeat->isFresh('time:trigger', 60));
    }

    public function test_破損したstatusファイルは記録なしとして扱われる()
    {
        $heartbeat = $this->heartbeat();
        file_put_contents($heartbeat->path(), '{invalid-json');

        $this->assertNull($heartbeat->status('time:trigger'));
        $this->assertFalse($heartbeat->isFresh('time:trigger', 300));
    }

    public function test_異なるタスク名は別々に記録される()
    {
        $heartbeat = $this->heartbeat();
        $heartbeat->recordSuccess('time:trigger');
        $heartbeat->recordFailure('results:delete');

        $this->assertSame('success', $heartbeat->status('time:trigger')['status']);
        $this->assertSame('failure', $heartbeat->status('results:delete')['status']);
        $this->assertNull($heartbeat->status('holidays:update'));
    }
}
