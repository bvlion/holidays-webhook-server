<?php

namespace App\Libs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "GET /health" が判定する内容をまとめる。公開レスポンスへは
 * component名とok/ngだけを返し、詳細な理由はログでのみ確認できるようにする
 * （実値・例外メッセージ・stack traceは含めない）。
 */
class HealthCheck
{
    /**
     * 稼働に必須な設定。実値は返さず、存在確認だけを行う。
     *
     * @var array<string, string>
     */
    private const REQUIRED_CONFIG = [
        'app_key' => 'app.key',
        'db_host' => 'database.connections.mysql.host',
        'db_database' => 'database.connections.mysql.database',
        'db_username' => 'database.connections.mysql.username',
        'db_password' => 'database.connections.mysql.password',
        'google_client_id' => 'services.google.client_id',
        'google_client_secret' => 'services.google.client_secret',
        'google_callback_url' => 'services.google.redirect',
        'google_calendar_api_key' => 'services.google_calendar.api_key',
    ];

    private const SCHEDULER_TASK = 'time:trigger';

    public function __construct(private SchedulerHeartbeat $heartbeat) {}

    /**
     * @return array{status: string, components: array<string, string>}
     */
    public function run(): array
    {
        $components = [
            'app' => 'ok',
            'database' => $this->checkDatabase() ? 'ok' : 'ng',
            'config' => $this->checkConfig() ? 'ok' : 'ng',
            'scheduler' => $this->checkScheduler() ? 'ok' : 'ng',
        ];

        $status = in_array('ng', $components, true) ? 'ng' : 'ok';

        return ['status' => $status, 'components' => $components];
    }

    private function checkDatabase(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (Throwable $e) {
            Log::error('health_check.database_failure', [
                'exception' => get_class($e),
            ]);

            return false;
        }
    }

    private function checkConfig(): bool
    {
        $missing = [];
        foreach (self::REQUIRED_CONFIG as $name => $configKey) {
            $value = config($configKey);
            if ($value === null || $value === '') {
                $missing[] = $name;
            }
        }

        if (! empty($missing)) {
            // 欠落している設定「名」だけを記録し、実値は記録しない。
            Log::error('health_check.config_missing', ['missing' => $missing]);

            return false;
        }

        return true;
    }

    private function checkScheduler(): bool
    {
        $threshold = (int) config('health.scheduler.time_trigger_heartbeat_threshold');
        $fresh = $this->heartbeat->isFresh(self::SCHEDULER_TASK, $threshold);

        if (! $fresh) {
            Log::error('health_check.scheduler_stale', [
                'task' => self::SCHEDULER_TASK,
                'threshold_seconds' => $threshold,
            ]);
        }

        return $fresh;
    }
}
