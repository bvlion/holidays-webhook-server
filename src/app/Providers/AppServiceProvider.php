<?php

namespace App\Providers;

use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('HolidayList', function() {
            return new \App\Libs\HolidayList();
        });

        // コンテナ経由で解決することで、テストからモック済みハンドラを注入できるようにする。
        $this->app->singleton(Client::class, function() {
            return new Client();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
