<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Google Calendar APIからの祝日取得に失敗したことを表す。
 *
 * 元のGuzzle例外のメッセージにはAPIキーを含むリクエストURLが含まれ得るため、
 * 意図的に元例外を $previous として保持せず、安全な固定メッセージだけを持つ。
 */
class HolidayFetchException extends RuntimeException {}
