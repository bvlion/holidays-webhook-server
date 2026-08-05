<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\Doubles\FakeHolidayList;
use Tests\TestCase;

class UpdateHolidayCacheCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_キャッシュをクリアしたうえで当年と翌年の日本の祝日を取得する()
    {
        $fake = new FakeHolidayList;
        $this->app->instance('HolidayList', $fake);

        Artisan::call('holidays:update');

        $this->assertSame(1, $fake->clearedCalls);
        $this->assertSame(['jp'.date('Y'), 'jp'.(date('Y') + 1)], $fake->requestedKeys);
    }
}
