<?php

use Illuminate\Support\Facades\Route;
use App\Models\User;
use App\Http\Controllers\GoogleLoginController;

Route::get('/', function () {
	return [
		'datetime' => date('Y-m-d H:i:s T'),
		'users' => User::all()
	];
});

Route::get('/auth/redirect', [GoogleLoginController::class, 'redirectGoogleAuth']);
Route::get('/login/callback', [GoogleLoginController::class, 'authGoogleCallback']);