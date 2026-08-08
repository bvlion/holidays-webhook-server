<?php

namespace App\Console\Commands;

use App\Libs\SchedulerHeartbeat;
use Illuminate\Console\Command;
use Throwable;

class UpdateHolidayCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'holidays:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '祝日キャッシュをアップデートする';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    private const HEARTBEAT_TASK = 'holidays:update';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $heartbeat = app(SchedulerHeartbeat::class);

        try {
            app()->make('HolidayList')->clear();
            // 日本だけキャッシュしておく
            app()->make('HolidayList')->getHolidays('jp', date('Y'));
            app()->make('HolidayList')->getHolidays('jp', date('Y') + 1);
            $heartbeat->recordSuccess(self::HEARTBEAT_TASK);
        } catch (Throwable $e) {
            $heartbeat->recordFailure(self::HEARTBEAT_TASK);

            throw $e;
        }
    }
}
