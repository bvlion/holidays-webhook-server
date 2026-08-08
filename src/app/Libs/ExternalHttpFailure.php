<?php

namespace App\Libs;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Facades\Log;

/**
 * HTTPレスポンスを伴わない外部HTTP失敗を、exec_resultsのNOT NULL制約に沿った
 * 安全な固定値へ変換し、秘密情報（URL・ヘッダー・ボディ）を含めずにログへ
 * 記録するための共通処理。
 *
 * DNS失敗・connect timeout・connection refused等の接続レベルの失敗は
 * {@see ConnectException} として送出され、これは
 * {@see RequestException} のサブクラスではない
 * （どちらも {@see TransferException} の兄弟クラス）。
 * そのため呼び出し側は RequestException だけでなく GuzzleException 全体を
 * catch し、レスポンスの有無は hasResponse() で判定すること。
 */
class ExternalHttpFailure
{
    /**
     * response_code は実際のHTTPステータスと衝突しない値（100〜599の範囲外）
     * を使い、「レスポンスを伴わない失敗」であることを明示する。
     */
    public const NO_RESPONSE_CODE = 0;

    public static function hasResponse(GuzzleException $e): bool
    {
        return $e instanceof RequestException && $e->getResponse() !== null;
    }

    /**
     * @return array{response_code: int, response_header: string, response_body: string}
     */
    public static function noResponsePayload(GuzzleException $e): array
    {
        return [
            'response_code' => self::NO_RESPONSE_CODE,
            'response_header' => json_encode(['x_status' => 'no_response'], JSON_THROW_ON_ERROR),
            'response_body' => json_encode([
                'error' => 'no_response',
                'exception' => get_class($e),
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function log(string $integration, GuzzleException $e, array $context = []): void
    {
        Log::warning('external_http.no_response', array_merge([
            'integration' => $integration,
            'exception' => get_class($e),
        ], $context));
    }
}
