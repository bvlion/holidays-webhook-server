<?php

namespace App\Libs;

use Carbon\CarbonImmutable;

/**
 * schedulerから起動されるArtisanコマンド（time:trigger・results:delete・
 * holidays:update）の最終成功・失敗時刻を logs/ 配下のstatus fileへ記録する。
 *
 * DB schema変更やLaravel migrationを新設せず、本番deployで"logs/"が保持される
 * 現在の構成を使って health check から scheduler停止を検知できるようにする
 * ための最小限の仕組み（Issue #225）。時刻はUTCのISO8601形式で保存し、
 * timezoneに依存した比較ミスを避ける。書き込みは一時ファイルへ書いてから
 * rename する atomic writeとし、読み込み側が壊れた内容を見ないようにする。
 */
class SchedulerHeartbeat
{
    private const STATUS_FILE = 'logs/scheduler-heartbeat.json';

    public function recordSuccess(string $task): void
    {
        $this->record($task, true);
    }

    public function recordFailure(string $task): void
    {
        $this->record($task, false);
    }

    /**
     * @return array{status: string, last_success_at: ?string, last_failure_at: ?string}|null
     */
    public function status(string $task): ?array
    {
        return $this->readUnlocked()[$task] ?? null;
    }

    public function isFresh(string $task, int $thresholdSeconds): bool
    {
        $status = $this->status($task);
        if ($status === null || empty($status['last_success_at'] ?? null)) {
            return false;
        }

        $lastSuccess = CarbonImmutable::parse($status['last_success_at']);

        // Carbon 3のdiffInSeconds()は既定で符号付きの差分を返すため、
        // 前後関係に依存しないよう明示的に絶対値を指定する。
        return CarbonImmutable::now()->diffInSeconds($lastSuccess, true) <= $thresholdSeconds;
    }

    public function path(): string
    {
        return $this->statusPath();
    }

    private function record(string $task, bool $success): void
    {
        $lockHandle = @fopen($this->lockPath(), 'c');
        if ($lockHandle === false) {
            return;
        }

        try {
            flock($lockHandle, LOCK_EX);
            $data = $this->readUnlocked();
            $now = CarbonImmutable::now('UTC')->toIso8601String();
            $entry = $data[$task] ?? [];
            $entry['status'] = $success ? 'success' : 'failure';
            if ($success) {
                $entry['last_success_at'] = $now;
            } else {
                $entry['last_failure_at'] = $now;
            }
            $data[$task] = $entry;
            $this->writeAtomic($data);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function readUnlocked(): array
    {
        $path = $this->statusPath();
        if (! is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, array<string, string>>  $data
     */
    private function writeAtomic(array $data): void
    {
        $path = $this->statusPath();
        $tmpPath = $path.'.'.getmypid().'.tmp';
        file_put_contents($tmpPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($tmpPath, $path);
    }

    private function statusPath(): string
    {
        return base_path(self::STATUS_FILE);
    }

    private function lockPath(): string
    {
        return $this->statusPath().'.lock';
    }
}
