<?php

declare(strict_types=1);

/**
 * PHP 8.5互換確認環境（Issue #215）専用。`composer install`を実行し、
 * 失敗した場合はIssue #216で追跡中の既知の非互換パッケージだけが原因かを
 * `composer why-not php <version> --locked` の構造化出力で機械的に判定する。
 *
 * 終了コードの意味:
 *   0 = composer install に成功した（以降のbootstrap等を通常どおり実行してよい）
 *   2 = 既知の非互換だけを検出した（bootstrap以降は未実施のまま成功扱いにしてよい）
 *   1 = 上記以外（未知の失敗。呼び出し側は必ず失敗として扱うこと）
 *
 * 依存パッケージがPHP 8.5対応版へ更新された場合は、このファイルの
 * KNOWN_INCOMPATIBLE_PACKAGES を更新（該当分を削除、あるいは全削除）すること。
 * 実際の検出結果とここに列挙した内容が完全一致しない限り、常に終了コード1で
 * 失敗するため、更新を怠って既知の状態のまま固定化されることはない。
 */
const EXPECTED_PHP_VERSION = '8.5.9';

const KNOWN_INCOMPATIBLE_PACKAGES = [
    'nette/schema' => 'v1.3.2',
    'nette/utils' => 'v4.0.5',
];

function fail(string $message): void
{
    fwrite(STDERR, '[php85-compat-check] ERROR: '.$message.PHP_EOL);
    exit(1);
}

/**
 * @param  list<string>  $command
 * @return array{0: string, 1: string, 2: int}
 */
function runCommand(array $command): array
{
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if ($process === false) {
        fail('コマンドを起動できませんでした: '.implode(' ', $command));
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [(string) $stdout, (string) $stderr, $exitCode];
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    if (PHP_VERSION !== EXPECTED_PHP_VERSION) {
        fail(sprintf(
            '想定するPHPバージョン(%s)と実際のPHPバージョン(%s)が一致しません。docker-compose.check-php85.ymlのPHP_IMAGE、またはこのスクリプトのEXPECTED_PHP_VERSIONを確認してください。',
            EXPECTED_PHP_VERSION,
            PHP_VERSION
        ));
    }

    $composerBinary = getenv('COMPOSER_BINARY');
    if ($composerBinary === false || $composerBinary === '') {
        $composerBinary = 'composer';
    }

    [$installStdout, $installStderr, $installExit] = runCommand([$composerBinary, 'install', '--no-interaction', '--prefer-dist']);
    fwrite(STDOUT, $installStdout);
    fwrite(STDERR, $installStderr);

    if ($installExit === 0) {
        fwrite(STDOUT, '[php85-compat-check] composer install に成功しました。以降のbootstrap・DB seed・静的解析・テストを通常どおり実行してください。'.PHP_EOL);
        exit(0);
    }

    fwrite(STDERR, '[php85-compat-check] composer install が失敗しました(exit='.$installExit.')。Issue #216で追跡中の既知の非互換だけが原因かを判定します。'.PHP_EOL);

    [$whyNotStdout, $whyNotStderr, $whyNotExit] = runCommand([$composerBinary, 'why-not', 'php', PHP_VERSION, '--locked', '--no-interaction']);

    // 競合が無い場合、"composer why-not" は exit 0 かつ標準出力が空になる
    // （メッセージは標準エラーへ出る）。この場合、composer installの失敗は
    // PHPバージョン制約以外の理由のはずなので、未知の失敗として扱う。
    if ($whyNotExit === 0) {
        fwrite(STDERR, $whyNotStdout.$whyNotStderr);
        fail('composer install は失敗しましたが、composer why-not php '.PHP_VERSION.' はPHPバージョンに起因する既知の競合を報告しませんでした。Issue #216の既知の非互換以外の理由で失敗している可能性があるため、未知の失敗として扱います。');
    }

    $lines = preg_split('/\R/', trim($whyNotStdout));
    $found = [];
    $constraints = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        if (! preg_match('/^(\S+)\s+(\S+)\s+requires php\s+\(([^)]*)\)/', $line, $matches)) {
            fwrite(STDERR, $whyNotStdout.$whyNotStderr);
            fail('composer why-not php '.PHP_VERSION.' の出力を解析できませんでした（想定外の行: "'.$line.'"）。既知の非互換(Issue #216)であることを機械的に確認できないため、未知の失敗として扱います。');
        }
        $found[$matches[1]] = $matches[2];
        $constraints[$matches[1]] = $matches[3];
    }

    if ($found === []) {
        fwrite(STDERR, $whyNotStdout.$whyNotStderr);
        fail('composer why-not php '.PHP_VERSION.' がexit '.$whyNotExit.' で終了しましたが、競合パッケージを1件も抽出できませんでした。未知の失敗として扱います。');
    }

    ksort($found);
    $expected = KNOWN_INCOMPATIBLE_PACKAGES;
    ksort($expected);

    if ($found !== $expected) {
        fwrite(STDERR, '検出された競合: '.json_encode($found, JSON_UNESCAPED_SLASHES).PHP_EOL);
        fwrite(STDERR, 'Issue #216で既知として登録されている競合: '.json_encode($expected, JSON_UNESCAPED_SLASHES).PHP_EOL);
        fail('composer installの失敗原因が、Issue #216で追跡している既知の非互換パッケージ・バージョンと完全には一致しませんでした。新規または変化した非互換の可能性があるため、未知の失敗として扱います。');
    }

    fwrite(STDOUT, '[php85-compat-check] Issue #216で追跡中の既知のPHP 8.5非互換だけを検出しました。'.PHP_EOL);
    foreach ($found as $name => $version) {
        fwrite(STDOUT, sprintf('  - %s %s requires php (%s)'.PHP_EOL, $name, $version, $constraints[$name]));
    }
    fwrite(STDOUT, '[php85-compat-check] composer install が完了していないため、Laravel bootstrap・DB seed・composer:validate/composer:audit/composer:prod-check・Laravel Pint・PHPStan(Larastan)・PHPUnitは未実施です。'.PHP_EOL);

    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, '[php85-compat-check] 予期しないエラーが発生しました: '.$e->getMessage().PHP_EOL);
    exit(1);
}
