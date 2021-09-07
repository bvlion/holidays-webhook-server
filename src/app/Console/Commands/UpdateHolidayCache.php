<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

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

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        app()->make('HolidayList')->clear();
        // 日本だけキャッシュしておく
        app()->make('HolidayList')->getHolidays('jp', date('Y'));
        app()->make('HolidayList')->getHolidays('jp', date('Y') + 1);
    }
}
