<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduler heartbeat
    |--------------------------------------------------------------------------
    |
    | "time:trigger" は毎分実行される想定だが、本番でLaravel Schedulerを
    | 起動するOS側cronの実際の間隔はリポジトリから確認できない
    | (docs/current-operations.md 5.5節)。そのため、想定間隔(1分)より
    | 十分に余裕を持たせた既定値とし、環境ごとに調整できるようにする。
    |
    */

    'scheduler' => [
        'time_trigger_heartbeat_threshold' => (int) env('HEALTH_SCHEDULER_HEARTBEAT_THRESHOLD', 300),
    ],

];
