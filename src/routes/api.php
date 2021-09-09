<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->get('/user', function (Request $request) {
  return $request->user();
});

Route::group(['middleware' => 'auth:api'], function () {
  Route::post('/exec/command/{id}', 'CommandExecController@command');
  Route::post('/exec/summary/{id}', 'CommandExecController@summary');
});