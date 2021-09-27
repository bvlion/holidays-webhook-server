<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Models\TimeTrigger;
use Illuminate\Support\Facades\DB;

class ExecResultsController extends BaseApiController
{
  public function results(Request $request, int $trigger_id)
  {
    $user = $request->user();
    $trigger = TimeTrigger::find($trigger_id);

    $this->checkExecutableUser(
      $trigger->target_type,
      $trigger->target_id,
      $user,
      'select'
    );

    return DB::select("
      SELECT 
        er.response_code,
        er.response_header,
        er.response_body,
        CONVERT_TZ(er.exec_time, '+09:00', tt.timezone) AS exec_time,
        tt.target_name AS trigger_name,
        CASE tt.command_id
          WHEN -1 THEN 'マナー解除'
          WHEN -2 THEN 'マナーモード'
          WHEN -3 THEN 'サイレント'
          ELSE c.target_name
        END AS command_name
      FROM
        exec_results er
        INNER JOIN time_triggers tt
          ON er.trigger_id = tt.id
         AND tt.deleted_at IS NULL
        LEFT OUTER JOIN commands c
          ON tt.command_id = c.id
         AND c.deleted_at IS NULL
        WHERE
          er.trigger_id = :id
    ", ['id' => $trigger_id]);
  }
}