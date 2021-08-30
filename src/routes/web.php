<?php

use Illuminate\Support\Facades\Route;
use App\Models\User;

Route::get('/', function () {
	return [
		'datetime' => date('Y-m-d H:i:s T'),
		'users' => User::all()
	];
});