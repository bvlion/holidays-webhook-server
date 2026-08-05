<?php

namespace Tests\Feature\Console;

use App\Models\Calender;
use App\Models\Command;
use App\Models\ExecResult;
use App\Models\OnetimeSkip;
use App\Models\TimeTrigger;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Doubles\FakeHolidayList;
use Tests\TestCase;

/**
 * time:trigger は判定にMySQLの NOW() を使うため、PHPのCarbon::setTestNow() では制御できない。
 * そのためMySQLの `SET timestamp` セッション変数でDB側の現在時刻を固定する
 * (DatabaseTransactionsがテスト終了時に接続を切断するため、次のテストへ影響しない)。
 * 一方、祝日判定 (HolidayList 経由) はPHPサーバーの実測 date() を使っており、
 * これはDB側の固定時刻と独立している(docs/current-architecture.md 10.1 に記載の既知の差異)。
 * そのため祝日を伴うテストでは、実行時点の実日付をキーにしたFakeHolidayListを都度用意する。
 */
class TimeTriggerCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const FIXED_JST_DATETIME = '2026-06-15 10:00:00';

    private function freezeDatabaseNow(string $jstDateTime = self::FIXED_JST_DATETIME): void
    {
        $timestamp = Carbon::createFromFormat('Y-m-d H:i:s', $jstDateTime, 'Asia/Tokyo')->getTimestamp();
        DB::statement('SET timestamp = '.$timestamp);
    }

    private function mysqlDayOfWeek(string $jstDateTime = self::FIXED_JST_DATETIME): int
    {
        // MySQL DAYOFWEEK(): 1=日曜日 ... 7=土曜日。CarbonのdayOfWeekは0=日曜日 ... 6=土曜日。
        return Carbon::createFromFormat('Y-m-d H:i:s', $jstDateTime, 'Asia/Tokyo')->dayOfWeek + 1;
    }

    private function bindMockClient(array $responses): void
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $this->app->instance(Client::class, new Client(['handler' => $handlerStack]));
    }

    private function fakeHolidaysForToday(bool $isHoliday, string $countryCode = 'jp'): void
    {
        $today = date('Y-m-d');
        $year = date('Y');
        $fake = new FakeHolidayList([
            $countryCode.$year => $isHoliday ? [$today => 'テスト祝日'] : [],
        ]);
        $this->app->instance('HolidayList', $fake);
    }

    private function createTrigger(array $overrides = []): TimeTrigger
    {
        $command = Command::factory()->create(['target_id' => 1, 'target_type' => 'user']);

        return TimeTrigger::factory()->create(array_merge([
            'command_id' => $command->id,
            'target_id' => 1,
            'target_type' => 'user',
            'time_from' => '10:00:00',
            'time_to' => '10:00:00',
            'exec_interval' => 1,
            'target_week' => json_encode([$this->mysqlDayOfWeek()]),
            'holiday_decision' => 'not_check',
            'timezone' => '+09:00',
            'country_code' => 'jp',
        ], $overrides));
    }

    public function test_条件を満たすトリガーは実行され結果が保存される()
    {
        $this->freezeDatabaseNow();
        $this->fakeHolidaysForToday(false);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $trigger = $this->createTrigger();

        Artisan::call('time:trigger');

        $this->assertSame(1, ExecResult::where('trigger_id', $trigger->id)->count());
        $result = ExecResult::where('trigger_id', $trigger->id)->first();
        $this->assertSame(200, $result->response_code);
        $this->assertSame((string) $trigger->command_id, (string) $result->command_id);
    }

    public function test_holiday_decisionがexecかつ祝日の場合は実行される()
    {
        $this->freezeDatabaseNow();
        $this->fakeHolidaysForToday(true);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $trigger = $this->createTrigger(['holiday_decision' => 'exec']);

        Artisan::call('time:trigger');

        $this->assertSame(1, ExecResult::where('trigger_id', $trigger->id)->count());
    }

    public function test_holiday_decisionがexecかつ祝日でない場合は実行されない()
    {
        $this->freezeDatabaseNow();
        $this->fakeHolidaysForToday(false);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $trigger = $this->createTrigger(['holiday_decision' => 'exec']);

        Artisan::call('time:trigger');

        $this->assertSame(0, ExecResult::where('trigger_id', $trigger->id)->count());
    }

    public function test_holiday_decisionがnot_execかつ祝日でなく対象曜日の場合は実行される()
    {
        $this->freezeDatabaseNow();
        $this->fakeHolidaysForToday(false);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $trigger = $this->createTrigger(['holiday_decision' => 'not_exec']);

        Artisan::call('time:trigger');

        $this->assertSame(1, ExecResult::where('trigger_id', $trigger->id)->count());
    }

    public function test_holiday_decisionがnot_execかつ祝日の場合は実行されない()
    {
        $this->freezeDatabaseNow();
        $this->fakeHolidaysForToday(true);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $trigger = $this->createTrigger(['holiday_decision' => 'not_exec']);

        Artisan::call('time:trigger');

        $this->assertSame(0, ExecResult::where('trigger_id', $trigger->id)->count());
    }

    public function test_対象曜日でない場合はnot_execでもnot_checkでも実行されない()
    {
        $this->freezeDatabaseNow();
        $this->fakeHolidaysForToday(false);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $otherWeekday = ($this->mysqlDayOfWeek() % 7) + 1; // 固定時刻の曜日とは異なる曜日
        $trigger = $this->createTrigger([
            'holiday_decision' => 'not_check',
            'target_week' => json_encode([$otherWeekday]),
        ]);

        Artisan::call('time:trigger');

        $this->assertSame(0, ExecResult::where('trigger_id', $trigger->id)->count());
    }

    public function test_個人カレンダーの祝日上書きは_google_calendarの判定より優先される()
    {
        // DB側で固定した日付(target_date)は、PHPサーバーの実日付を使うGoogle Calendar側の
        // 判定基準日とは独立している(docs/current-architecture.md 10.1)。
        // ここではGoogle側を非祝日、個人カレンダー側を祝日にして優先順位を確認する。
        $this->freezeDatabaseNow();
        $this->fakeHolidaysForToday(false);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $trigger = $this->createTrigger(['holiday_decision' => 'exec']);
        Calender::factory()->create([
            'target_id' => $trigger->target_id,
            'target_type' => $trigger->target_type,
            'target_date' => '2026-06-15',
            'is_holiday' => 1,
        ]);

        Artisan::call('time:trigger');

        $this->assertSame(1, ExecResult::where('trigger_id', $trigger->id)->count());
    }

    public function test_実行間隔で割り切れない時刻では実行されない()
    {
        $this->freezeDatabaseNow(); // 10:00:00
        $this->fakeHolidaysForToday(false);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $trigger = $this->createTrigger([
            'time_from' => '09:00:00',
            'time_to' => '23:59:00',
            'exec_interval' => 7, // 09:00 から 60分後 % 7 = 4 (!= 0)
        ]);

        Artisan::call('time:trigger');

        $this->assertSame(0, ExecResult::where('trigger_id', $trigger->id)->count());
    }

    public function test_実行間隔で割り切れる時刻では実行される()
    {
        $this->freezeDatabaseNow(); // 10:00:00
        $this->fakeHolidaysForToday(false);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $trigger = $this->createTrigger([
            'time_from' => '09:00:00',
            'time_to' => '23:59:00',
            'exec_interval' => 15, // 09:00 から 60分後 % 15 = 0
        ]);

        Artisan::call('time:trigger');

        $this->assertSame(1, ExecResult::where('trigger_id', $trigger->id)->count());
    }

    public function test_未使用のワンタイムスキップがあれば実行されずスキップが消費される()
    {
        $this->freezeDatabaseNow();
        $this->fakeHolidaysForToday(false);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $trigger = $this->createTrigger();
        $skip = OnetimeSkip::factory()->create(['target_id' => $trigger->id, 'target_type' => 'time']);

        Artisan::call('time:trigger');

        $this->assertSame(0, ExecResult::where('trigger_id', $trigger->id)->count());
        $this->assertNotNull($skip->fresh()->deleted_at);
    }

    public function test_exec_flagが0のトリガーは対象外になる()
    {
        $this->freezeDatabaseNow();
        $this->fakeHolidaysForToday(false);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $trigger = $this->createTrigger(['exec_flag' => 0]);

        Artisan::call('time:trigger');

        $this->assertSame(0, ExecResult::where('trigger_id', $trigger->id)->count());
    }

    public function test_論理削除済みのトリガーは対象外になる()
    {
        $this->freezeDatabaseNow();
        $this->fakeHolidaysForToday(false);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $trigger = $this->createTrigger(['deleted_at' => now()]);

        Artisan::call('time:trigger');

        $this->assertSame(0, ExecResult::where('trigger_id', $trigger->id)->count());
    }

    public function test_同じ分に複数回起動すると重複して実行される()
    {
        // docs/current-architecture.md 8.4 に記載された、重複実行を防ぐ仕組みが無い現行仕様の回帰テスト。
        // 修正はこのIssueの対象外。
        $this->freezeDatabaseNow();
        $this->fakeHolidaysForToday(false);
        $this->bindMockClient([new Response(200, [], 'first'), new Response(200, [], 'second')]);
        $trigger = $this->createTrigger();

        Artisan::call('time:trigger');
        Artisan::call('time:trigger');

        $this->assertSame(2, ExecResult::where('trigger_id', $trigger->id)->count());
    }

    public function test_年末年始の日付境界でも時間帯と曜日の判定は正しく行われる()
    {
        $newYearsEve = '2025-12-31 23:59:00';
        $this->freezeDatabaseNow($newYearsEve);
        $this->fakeHolidaysForToday(false);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $trigger = $this->createTrigger([
            'time_from' => '23:59:00',
            'time_to' => '23:59:00',
            'target_week' => json_encode([$this->mysqlDayOfWeek($newYearsEve)]),
        ]);

        Artisan::call('time:trigger');

        $this->assertSame(1, ExecResult::where('trigger_id', $trigger->id)->count());
    }

    public function test_うるう年の2月29日でも時間帯と曜日の判定は正しく行われる()
    {
        $leapDay = '2024-02-29 12:00:00';
        $this->freezeDatabaseNow($leapDay);
        $this->fakeHolidaysForToday(false);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $trigger = $this->createTrigger([
            'time_from' => '12:00:00',
            'time_to' => '12:00:00',
            'target_week' => json_encode([$this->mysqlDayOfWeek($leapDay)]),
        ]);

        Artisan::call('time:trigger');

        $this->assertSame(1, ExecResult::where('trigger_id', $trigger->id)->count());
    }

    public function test_トリガーのタイムゾーンが_asia_tokyoと異なる場合も判定に反映される()
    {
        // DBの現在時刻は常に+09:00として保持される前提のため、+05:00のトリガーは
        // 対象タイムゾーンへ変換した時刻(+09:00の4時間前)で時間帯を判定する。
        $this->freezeDatabaseNow('2026-06-15 10:00:00'); // +09:00 → +05:00 換算で 06:00
        $this->fakeHolidaysForToday(false);
        $this->bindMockClient([new Response(200, [], 'ok')]);
        $trigger = $this->createTrigger([
            'timezone' => '+05:00',
            'time_from' => '06:00:00',
            'time_to' => '06:00:00',
            'target_week' => json_encode([$this->mysqlDayOfWeek('2026-06-15 10:00:00')]),
        ]);

        Artisan::call('time:trigger');

        $this->assertSame(1, ExecResult::where('trigger_id', $trigger->id)->count());
    }
}
