<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Promise;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use App\Models\ExecResult;
use App\Models\User;
use App\Models\OnetimeSkip;

class TimeTrigger extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'time:trigger';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = '時間指定でコマンドを実行する';

  /**
   * Create a new command instance.
   *
   * @return void
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Execute the console command.
   *
   * @return int
   */
  public function handle()
  {
    // 実行対象を取得
    $triggers = DB::select("
      SELECT 
        c.id AS id,
        tt.target_id AS target_id,
        tt.target_type AS target_type,
        tt.target_name AS trigger_name,
        c.target_name AS command_name,
        target_week,
        holiday_decision,
        exec_notify,
        country_code,
        DAYOFWEEK(CONVERT_TZ(NOW(), '+09:00', tt.timezone)) AS week,
        CONVERT_TZ(NOW(), '+09:00', tt.timezone) AS exec_time,
        url,
        method,
        headers,
        parameters,
        body_type,
        cal.is_holiday AS user_holiday,
        ones.id AS skip_id
      FROM
        time_triggers tt
        INNER JOIN commands c
          ON tt.command_id = c.id
         AND c.deleted_at IS NULL
        -- 個人の祝日を無し想定で JOIN
        LEFT OUTER JOIN calenders cal
          ON cal.target_id = tt.target_id
         AND cal.target_type = tt.target_type
         AND cal.target_date = DATE_FORMAT(CONVERT_TZ(NOW(), '+09:00', tt.timezone), '%Y-%m-%d')
         AND cal.deleted_at IS NULL
        -- スキップ対象を無し想定で JOIN
        LEFT OUTER JOIN onetime_skips ones
          ON ones.target_id = tt.id
         AND ones.target_type = 'time'
         AND ones.deleted_at IS NULL
      WHERE
          -- 現在を対象タイムゾーン日時に変換して範囲内かを確認
          CAST(DATE_FORMAT(CONVERT_TZ(NOW(), '+09:00', tt.timezone), '%H:%i') AS TIME) BETWEEN tt.time_from AND tt.time_to
          -- 現在の対象タイムゾーンの分 - 開始時間の分 % インターバル == 0
        AND CAST(TIME_TO_SEC(DATE_FORMAT(CONVERT_TZ(NOW(), '+09:00', tt.timezone), '%H:%i')) / 60 - TIME_TO_SEC(tt.time_from) / 60 AS SIGNED) % tt.exec_interval = 0
        AND tt.exec_flag = 1
        AND tt.deleted_at IS NULL
    ");

    if (empty($triggers)) {
      return;
    }

    $client = new Client();

    $requests = function() use ($client, $triggers) {
      foreach ($triggers as $key => $trigger) {
        // スキップ対象であれば飛ばす
        if ($trigger->skip_id !== null) {
          $skip = OnetimeSkip::find($trigger->skip_id);
          $skip->deleted_at = date('Y-m-d H:i:s');
          $skip->save();
          continue;
        }

        // 祝日判定
        $is_holiday = array_key_exists(date('Y-m-d'), app()->make('HolidayList')->getHolidays($trigger->country_code, date('Y')));

        // 個人カレンダーがあった場合はそちらを優先する
        if ($trigger->user_holiday !== null) {
          $is_holiday = $trigger->user_holiday;
        }

        // 曜日を展開
        $target_weeks = json_decode($trigger->target_week, true);

        // 含めるパターン以外を除外
        if (!(
          ($trigger->holiday_decision == 'exec' && $is_holiday) || // 祝日を含める && 祝日
          ($trigger->holiday_decision == 'not_exec' && !$is_holiday && array_key_exists($trigger->week, $target_weeks)) || // 祝日を含めない && !祝日 && 対象曜日
          ($trigger->holiday_decision == 'not_check' && array_key_exists($trigger->week, $target_weeks)) // 祝日判定しない && 対象曜日
        )) {
          continue;
        }

        // 実行
        $options['allow_redirects'] = true;

        $headers = json_decode($trigger->headers, true);
        if (!empty($headers)) {
          $options['headers'] = $headers;
        }

        if (!empty($trigger->parameters)) {
          $parameter = str_replace('##DATETIME##', date('Y-m-d H:i:s'), $trigger->parameters);
          if ($trigger->body_type == 'json') {
            $options[$trigger->body_type] = $parameter;
          } else {
            $options[$trigger->body_type] = json_decode($parameter, true);
          }
        }

        yield function() use ($client, $trigger, $options) {
          $promise = $client->requestAsync($trigger->method, $trigger->url, $options);
          $promise->then(
            function(ResponseInterface $res) use ($trigger) {
              $this->saveResult($trigger, $res);
            },
            //Rejected 
            function(RequestException $e) use ($trigger) {
              $this->saveResult($trigger, $e->getResponse());
            }
          );
          return $promise;
        };
      }

    };

    $pool = new Pool($client, $requests());
    $promise = $pool->promise();
    $promise->wait();
  }

  private function saveResult($trigger, ResponseInterface $res) {
    ExecResult::create([
      'command_id' => $trigger->id,
      'exec_time' => $trigger->exec_time,
      'response_code' => $res->getStatusCode(),
      'response_header' => json_encode($res->getHeaders(), true),
      'response_body' => $res->getBody(),
    ]);

    // FCM 通知 TODO
    if ($trigger->exec_notify) {
      $fcm_users = [];
      if ($trigger->target_type == 'group') {
        $fcm_users = User::where('groups_id', $trigger->target_id)->whereNotNull('fcm_token')->get();
      } else {
        $fcm_users = User::where('id', $trigger->target_id)->whereNotNull('fcm_token')->get();
      }
      "「{$trigger->trigger_name}」によって「{$trigger->command_name}」が実行されました。";
    }
  }
}
