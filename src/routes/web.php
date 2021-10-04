<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

Route::get('/', function () {
  return [
    'server' => date('Y-m-d H:i:s T'),
    'db' => DB::select('SELECT NOW() AS time')[0]->time,
  ];
});

Route::get('/holiday/cache/clear', function () {
  return [
    'holidays' => app()->make('HolidayList')->clear()
  ];
});

Route::get('/auth/redirect', 'GoogleLoginController@redirectGoogleAuth');
Route::get('/login/callback', 'GoogleLoginController@authGoogleCallback');

Route::get('/doc', 'RedocController@index');
Route::get('/openapi.yml', 'RedocController@yaml');