<?php

namespace Tests\Feature\Console;

use App\Libs\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\Doubles\FakeHolidayList;
use Tests\TestCase;
use Throwable;

class UpdateHolidayCacheCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        $path = app(SchedulerHeartbeat::class)->path();
        @unlink($path);
        @unlink($path.'.lock');

        parent::tearDown();
    }

    public function test_キャッシュをクリアしたうえで当年と翌年の日本の祝日を取得する()
    {
        $fake = new FakeHolidayList;
        $this->app->instance('HolidayList', $fake);

        Artisan::call('holidays:update');

        $this->assertSame(1, $fake->clearedCalls);
        $this->assertSame(['jp'.date('Y'), 'jp'.(date('Y') + 1)], $fake->requestedKeys);
    }

    public function test_成功時はheartbeatに成功時刻が記録される()
    {
        $this->app->instance('HolidayList', new FakeHolidayList);

        Artisan::call('holidays:update');

        $heartbeat = app(SchedulerHeartbeat::class);
        $status = $heartbeat->status('holidays:update');
        $this->assertSame('success', $status['status']);
        $this->assertNotEmpty($status['last_success_at']);
    }

    public function test_失敗時はheartbeatが失敗として記録され成功時刻は記録されない()
    {
        $failing = new class
        {
            public function clear()
            {
                throw new RuntimeException('clear failed');
            }

            public function getHolidays($code, $year)
            {
                return [];
            }
        };
        $this->app->instance('HolidayList', $failing);

        try {
            Artisan::call('holidays:update');
            $this->fail('例外が発生することを期待しています');
        } catch (Throwable $e) {
            // 想定どおりの失敗
        }

        $heartbeat = app(SchedulerHeartbeat::class);
        $status = $heartbeat->status('holidays:update');
        $this->assertSame('failure', $status['status']);
        $this->assertArrayNotHasKey('last_success_at', $status);
    }
}
