<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return [
        'server' => date('Y-m-d H:i:s T'),
        'db' => DB::select('SELECT NOW() AS time')[0]->time,
    ];
});

Route::get('/health', 'HealthController@index');

Route::get('/holiday/cache/clear', function () {
    return [
        'holidays' => app()->make('HolidayList')->clear(),
    ];
});

Route::get('/auth/redirect', 'GoogleLoginController@redirectGoogleAuth');
Route::get('/login/callback', 'GoogleLoginController@authGoogleCallback');

Route::get('/doc', 'RedocController@index');
Route::get('/openapi.yml', 'RedocController@yaml');
