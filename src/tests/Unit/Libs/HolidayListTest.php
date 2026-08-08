<?php

namespace Tests\Unit\Libs;

use App\Exceptions\HolidayFetchException;
use App\Libs\HolidayList;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HolidayListTest extends TestCase
{
    private function bindMockClient(array $responses): void
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $this->app->instance(Client::class, new Client(['handler' => $handlerStack]));
    }

    private function cacheFile(): string
    {
        return base_path('logs/holidays.json');
    }

    protected function setUp(): void
    {
        parent::setUp();
        @unlink($this->cacheFile());
        config(['services.google_calendar.api_key' => 'test-calendar-api-key']);
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheFile());
        parent::tearDown();
    }

    public function test_取得に成功すると祝日一覧を返しキャッシュへ保存する()
    {
        $body = json_encode([
            'items' => [
                ['start' => ['date' => '2026-01-01'], 'summary' => '元日'],
            ],
        ]);
        $this->bindMockClient([new Response(200, [], $body)]);

        $holidays = (new HolidayList)->getHolidays('jp', 2026);

        $this->assertSame(['2026-01-01' => '元日'], $holidays);
        $this->assertFileExists($this->cacheFile());
    }

    public function test_接続失敗時はholiday_fetch_exceptionを投げapiキーやurlをログへ出さない()
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                $context_json = json_encode($context);

                return $message === 'external_service.failure'
                    && $context['integration'] === 'google_calendar'
                    && ! str_contains($context_json, 'test-calendar-api-key')
                    && ! str_contains($context_json, 'googleapis.com');
            });
        $this->bindMockClient([
            new ConnectException(
                'cURL error 6: Could not resolve host: www.googleapis.com (key=test-calendar-api-key)',
                new Psr7Request(
                    'GET',
                    'https://www.googleapis.com/calendar/v3/calendars/x/events?key=test-calendar-api-key'
                )
            ),
        ]);

        $holidayList = new HolidayList;

        try {
            $holidayList->getHolidays('jp', 2026);
            $this->fail('例外が発生することを期待しています');
        } catch (HolidayFetchException $e) {
            $this->assertStringNotContainsString('test-calendar-api-key', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        $this->assertFileDoesNotExist($this->cacheFile());
    }

    public function test_4xxレスポンスでも例外メッセージとログにapiキーを含めない()
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'external_service.failure' && $context['status'] === 403;
            });
        $this->bindMockClient([new Response(403, [], 'Forbidden')]);

        $holidayList = new HolidayList;

        try {
            $holidayList->getHolidays('jp', 2026);
            $this->fail('例外が発生することを期待しています');
        } catch (HolidayFetchException $e) {
            $this->assertStringNotContainsString('test-calendar-api-key', $e->getMessage());
        }
    }
}
