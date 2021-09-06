<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\GoogleLoginController;

Route::get('/', function () {
  return [
    'server' => date('Y-m-d H:i:s T'),
    'db' => DB::select('SELECT NOW() AS time')[0]->time
  ];
});

Route::get('/auth/redirect', [GoogleLoginController::class, 'redirectGoogleAuth']);
Route::get('/login/callback', [GoogleLoginController::class, 'authGoogleCallback']);