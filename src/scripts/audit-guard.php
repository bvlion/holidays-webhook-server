<?php

declare(strict_types=1);

/**
 * `composer audit --locked --format=json` を実行し、構造化された出力を検証する。
 *
 * Composer 2.8.12はアドバイザリ取得（Packagistへの通信）自体に失敗した場合、
 * 例外を捕捉せずに異常終了する（終了コード100・標準出力は空）ため、
 * 通常の `composer audit` 呼び出しだけでは「監査に成功し脆弱性がなかった」場合と
 * 見分けが付かない事態は起きない。ただし将来のComposerの挙動変化にも備え、
 * 終了コードとJSON構造の両方を根拠に判定する。
 */
function fail(string $message): void
{
    fwrite(STDERR, '[audit-guard] ERROR: '.$message.PHP_EOL);
    exit(1);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $composerBinary = getenv('COMPOSER_BINARY');
    if ($composerBinary === false || $composerBinary === '') {
        $composerBinary = 'composer';
    }

    $command = [$composerBinary, 'audit', '--locked', '--no-interaction', '--format=json'];

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if ($process === false) {
        fail('composer audit を起動できませんでした。');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    // composer auditが正常完了した場合の終了コードは 0(OK) / 1(脆弱性あり) / 2(abandonedあり) / 3(両方) のビットマスクのみ。
    // それ以外（接続失敗時などに観測される100等）は、アドバイザリ取得自体が失敗したとみなす。
    if (! in_array($exitCode, [0, 1, 2, 3], true)) {
        fwrite(STDERR, $stderr);
        fail(sprintf(
            'composer audit が想定外の終了コード(%d)で終了しました。アドバイザリ取得自体に失敗した可能性があります。',
            $exitCode
        ));
    }

    if (trim((string) $stdout) === '') {
        fwrite(STDERR, $stderr);
        fail('composer audit の出力が空でした。アドバイザリ取得に失敗した可能性があります。');
    }

    try {
        $result = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, $stdout.PHP_EOL.$stderr);
        fail('composer audit の出力がJSONとして解析できませんでした: '.$e->getMessage());
    }

    if (
        ! is_array($result)
        || ! array_key_exists('advisories', $result) || ! is_array($result['advisories'])
        || ! array_key_exists('abandoned', $result) || ! is_array($result['abandoned'])
    ) {
        fwrite(STDERR, $stdout);
        fail('composer audit の出力に必要なフィールド(advisories/abandoned)が含まれていません。');
    }

    // ignoreに登録されていないアドバイザリ（baseline外）が1件でもあれば失敗する。
    $unignoredAdvisories = $result['advisories'];
    if ($unignoredAdvisories !== []) {
        $packages = array_keys($unignoredAdvisories);
        fwrite(STDERR, sprintf(
            'baselineに含まれない脆弱性アドバイザリが %d パッケージで見つかりました: %s'.PHP_EOL,
            count($packages),
            implode(', ', $packages)
        ));
        fail('composer.json の config.audit.ignore に登録されていない脆弱性アドバイザリが存在します。');
    }

    $ignoredAdvisories = $result['ignored-advisories'] ?? [];
    if (! is_array($ignoredAdvisories)) {
        fail('composer audit の出力の ignored-advisories フィールドが不正です。');
    }

    $ignoredPackages = array_keys($ignoredAdvisories);
    $ignoredCount = 0;
    foreach ($ignoredAdvisories as $packageAdvisories) {
        if (! is_array($packageAdvisories)) {
            fail('composer audit の出力の ignored-advisories フィールドが不正です。');
        }
        $ignoredCount += count($packageAdvisories);
    }

    // abandoned packageは現状 config.audit.abandoned=report のため、検知しても失敗させずログにのみ出す。
    $abandoned = $result['abandoned'];
    if ($abandoned !== []) {
        fwrite(STDOUT, sprintf(
            '[audit-guard] abandoned package を %d 件検知しました(abandoned=report方針のため失敗にはしません): %s'.PHP_EOL,
            count($abandoned),
            implode(', ', array_keys($abandoned))
        ));
    }

    fwrite(STDOUT, sprintf(
        '[audit-guard] composer audit は正常に完了しました。baseline化済みの脆弱性アドバイザリ %d 件（%d パッケージ）を無視しました: %s'.PHP_EOL,
        $ignoredCount,
        count($ignoredPackages),
        implode(', ', $ignoredPackages)
    ));

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[audit-guard] 予期しないエラーが発生しました: '.$e->getMessage().PHP_EOL);
    exit(1);
}
