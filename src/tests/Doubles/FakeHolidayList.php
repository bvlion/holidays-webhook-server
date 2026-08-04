<?php

namespace Tests\Doubles;

/**
 * app()->make('HolidayList') はダミー文字列キーで解決される (App\Providers\AppServiceProvider参照)。
 * 型宣言のない呼び出し規約に合わせたテスト用の差し替えで、実際のGoogle Calendar APIへは通信しない。
 */
class FakeHolidayList
{
    /** @var array<string, array<string, string>> */
    private $holidaysByKey;

    /** @var string[] */
    public $requestedKeys = [];

    /** @var int */
    public $clearedCalls = 0;

    public function __construct(array $holidaysByKey = [])
    {
        $this->holidaysByKey = $holidaysByKey;
    }

    public function getHolidays($code, $year)
    {
        $key = $code . $year;
        $this->requestedKeys[] = $key;

        return $this->holidaysByKey[$key] ?? [];
    }

    public function clear()
    {
        $this->clearedCalls++;

        return [];
    }
}
