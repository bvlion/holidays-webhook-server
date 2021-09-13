<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Calender;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CalendarController extends Controller
{
  public function isHoliday(Request $request)
  {
    $type = $request->type;
    $target_id = $request->user()->id;
    if ($type == 'group') {
      $target_id = $request->user()->groups_id;
    }
    $date = $request->date;
    $country_code = $request->user()->country_code;
    if ($request->country_code !== null) {
      $country_code = $request->country_code;
    }

    if ($type === null || $date === null) {
      throw new HttpException(404, 'No Parameter');
    }

    try {
      $date = date('Y-m-d', strtotime($date));
    } catch (Exception $e) {
      throw new HttpException(400, 'Not date pattern');
    }

    $is_holiday = array_key_exists($date, app()->make('HolidayList')->getHolidays($country_code, substr($date, 0, 4)));
    $force = false;
    $calandar = Calender::where('target_id', $target_id)->where('target_type', $type)->where('target_date', $date)->whereNull('deleted_at')->first();
    if ($calandar) {
      $is_holiday = $calandar->is_holiday == 1;
      $force = true;
    }

    return ['holiday' => $is_holiday, 'force' => $force];
  }

  public function upsert(Request $request)
  {
    $type = $request->type;
    $target_id = $request->user()->id;
    if ($type == 'group') {
      $target_id = $request->user()->groups_id;
    }
    $date = $request->date;

    if ($type === null || $date === null) {
      throw new HttpException(404, 'No Parameter');
    }

    try {
      $date = date('Y-m-d', strtotime($date));
    } catch (Exception $e) {
      throw new HttpException(400, 'Not date pattern');
    }

    $is_holiday = $request->holiday;

    $calendar = Calender::updateOrCreate([
      'target_id' => $target_id,
      'target_type' => $type,
      'target_date' => $date,
    ], [
      'is_holiday' => $is_holiday
    ]);

    return $calendar;
  }

}