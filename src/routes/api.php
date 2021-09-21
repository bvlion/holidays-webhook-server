<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => 'auth:api'], function () {
  Route::post('/exec/command/{id}', 'CommandExecController@command');
  Route::post('/exec/summary/{id}', 'CommandExecController@summary');
  Route::get('/calendar/holiday', 'CalendarController@isHoliday');
  Route::post('/calendar/upsert', 'CalendarController@upsert');

  Route::resource('commands', 'CommandsController', ['except' => ['show', 'create', 'edit']]);

});