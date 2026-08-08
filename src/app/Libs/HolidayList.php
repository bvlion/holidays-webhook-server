<?php

namespace App\Libs;

use App\Exceptions\HolidayFetchException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class HolidayList
{
    private $_holidays = [];

    public function clear()
    {
        Log::info('holidays', ['clear' => base_path(self::HOLIDAYS_FILE)]);
        $this->_holidays = [];
        unlink(base_path(self::HOLIDAYS_FILE));

        return $this->_holidays;
    }

    public function getHolidays(string $code, int $year)
    {
        $key = $code.$year;
        if (array_key_exists($key, $this->_holidays)) {
            return $this->_holidays[$key];
        }

        if (file_exists(base_path(self::HOLIDAYS_FILE))) {
            $this->_holidays = json_decode(file_get_contents(base_path(self::HOLIDAYS_FILE)), true);
            if (array_key_exists($key, $this->_holidays)) {
                return $this->_holidays[$key];
            }
        }

        // カレンダーIDに含まれる "#" は事前に %23 へエンコードされた形式で
        // Googleのドキュメントに記載されている。Guzzleは有効な%エンコード済み
        // 文字列を再エンコードしないため、パス部分に直接埋め込む。
        $calendar_path = sprintf(
            'https://www.googleapis.com/calendar/v3/calendars/%s/events',
            'ja.'.self::COUNTRY_CODES[$code].'.official%23holiday@group.v.calendar.google.com'
        );

        $client = app(Client::class);

        try {
            $response = $client->request('GET', $calendar_path, [
                'query' => [
                    'key' => config('services.google_calendar.api_key'),
                    'timeMin' => $year.'-01-01T00:00:00.000Z',
                    'timeMax' => $year.'-12-31T00:00:00.000Z',
                    'orderBy' => 'startTime',
                    'singleEvents' => 'true',
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::warning('external_service.failure', [
                'integration' => 'google_calendar',
                'exception' => get_class($e),
                'status' => $this->safeStatusCode($e),
            ]);

            // 元例外にはAPIキーを含むURLが含まれ得るため $previous としては保持しない。
            throw new HolidayFetchException('Google Calendar APIからの祝日取得に失敗しました。');
        }

        $result_data = json_decode((string) $response->getBody());
        $holidays = [];
        foreach ($result_data->items ?? [] as $item) {
            $holidays[date('Y-m-d', strtotime($item->start->date))] = $item->summary;
        }
        ksort($holidays);

        $this->_holidays[$key] = $holidays;
        Log::info('holidays', ['create' => $key]);
        file_put_contents(base_path(self::HOLIDAYS_FILE), json_encode($this->_holidays, true));

        return $holidays;
    }

    private function safeStatusCode(GuzzleException $e): ?int
    {
        if ($e instanceof RequestException && $e->getResponse() !== null) {
            return $e->getResponse()->getStatusCode();
        }

        return null;
    }

    private const HOLIDAYS_FILE = 'logs/holidays.json';

    private const COUNTRY_CODES = [
        'is' => 'is',
        'az' => 'az',
        'ie' => 'irish',
        'af' => 'af',
        'us' => 'usa',
        'ae' => 'ae',
        'dz' => 'dz',
        'ar' => 'ar',
        'al' => 'al',
        'aw' => 'aw',
        'am' => 'am',
        'ai' => 'ai',
        'ao' => 'ao',
        'ag' => 'ag',
        'ad' => 'ad',
        'ye' => 'ye',
        'gb' => 'uk',
        'il' => 'jewish',
        'it' => 'italian',
        'iq' => 'iq',
        'ir' => 'ir',
        'id' => 'indonesian',
        'in' => 'indian',
        'wf' => 'wf',
        'ug' => 'ug',
        'ua' => 'ukrainian',
        'uz' => 'uz',
        'uy' => 'uy',
        'ec' => 'ec',
        'eg' => 'eg',
        'ee' => 'ee',
        'et' => 'et',
        'er' => 'er',
        'sv' => 'sv',
        'au' => 'australian',
        'at' => 'austrian',
        'om' => 'om',
        'nl' => 'dutch',
        'gh' => 'gh',
        'cv' => 'cv',
        'gg' => 'gg',
        'gy' => 'gy',
        'kz' => 'kz',
        'qa' => 'qa',
        'ca' => 'canadian',
        'ga' => 'ga',
        'cm' => 'cm',
        'gm' => 'gm',
        'kh' => 'kh',
        'gn' => 'gn',
        'gw' => 'gw',
        'cy' => 'cy',
        'cu' => 'cu',
        'cw' => 'cw',
        'gr' => 'greek',
        'ki' => 'ki',
        'kg' => 'kg',
        'gt' => 'gt',
        'gu' => 'gu',
        'kw' => 'kw',
        'ck' => 'ck',
        'gl' => 'gl',
        'ge' => 'ge',
        'gd' => 'gd',
        'hr' => 'croatian',
        'ky' => 'ky',
        'ke' => 'ke',
        'ci' => 'ci',
        'cr' => 'cr',
        'km' => 'km',
        'co' => 'co',
        'cg' => 'cg',
        'cd' => 'cd',
        'sa' => 'saudiarabian',
        'ws' => 'ws',
        'bl' => 'bl',
        'st' => 'st',
        'zm' => 'zm',
        'pm' => 'pm',
        'sm' => 'sm',
        'mf' => 'mf',
        'sl' => 'sl',
        'dj' => 'dj',
        'gi' => 'gi',
        'je' => 'je',
        'jm' => 'jm',
        'sy' => 'sy',
        'sg' => 'singapore',
        'sx' => 'sx',
        'zw' => 'zw',
        'sd' => 'sd',
        'ch' => 'ch',
        'se' => 'swedish',
        'es' => 'spain',
        'sr' => 'sr',
        'lk' => 'lk',
        'sk' => 'slovak',
        'si' => 'slovenian',
        'sz' => 'sz',
        'sc' => 'sc',
        'sn' => 'sn',
        'rs' => 'rs',
        'kn' => 'kn',
        'vc' => 'vc',
        'sh' => 'sh',
        'lc' => 'lc',
        'so' => 'so',
        'sb' => 'sb',
        'tc' => 'tc',
        'th' => 'th',
        'tj' => 'tj',
        'tz' => 'tz',
        'cz' => 'czech',
        'td' => 'td',
        'tn' => 'tn',
        'cl' => 'cl',
        'tv' => 'tv',
        'dk' => 'danish',
        'tg' => 'tg',
        'de' => 'german',
        'dm' => 'dm',
        'do' => 'do',
        'tt' => 'tt',
        'tm' => 'tm',
        'tr' => 'turkish',
        'to' => 'to',
        'ng' => 'ng',
        'nr' => 'nr',
        'na' => 'na',
        'ni' => 'ni',
        'ne' => 'ne',
        'nc' => 'nc',
        'nz' => 'new_zealand',
        'np' => 'np',
        'no' => 'norwegian',
        'bh' => 'bh',
        'ht' => 'ht',
        'pk' => 'pk',
        'pa' => 'pa',
        'vu' => 'vu',
        'bs' => 'bs',
        'pg' => 'pg',
        'bm' => 'bm',
        'pw' => 'pw',
        'py' => 'py',
        'bb' => 'bb',
        'hu' => 'hungarian',
        'bd' => 'bd',
        'bt' => 'bt',
        'fj' => 'fj',
        'ph' => 'philippines',
        'fi' => 'finnish',
        'pr' => 'pr',
        'fo' => 'fo',
        'fk' => 'fk',
        'br' => 'brazilian',
        'fr' => 'french',
        'bg' => 'bulgarian',
        'bf' => 'bf',
        'bn' => 'bn',
        'bi' => 'bi',
        'vn' => 'vietnamese',
        'bj' => 'bj',
        've' => 've',
        'by' => 'by',
        'bz' => 'bz',
        'pe' => 'pe',
        'be' => 'be',
        'pl' => 'polish',
        'ba' => 'ba',
        'bw' => 'bw',
        'bo' => 'bo',
        'pt' => 'portuguese',
        'hn' => 'hn',
        'mh' => 'mh',
        'yt' => 'yt',
        'mo' => 'mo',
        'mk' => 'mk',
        'mg' => 'mg',
        'mw' => 'mw',
        'ml' => 'ml',
        'mt' => 'mt',
        'mq' => 'mq',
        'my' => 'malaysia',
        'im' => 'im',
        'fm' => 'fm',
        'mm' => 'mm',
        'mx' => 'mexican',
        'mu' => 'mu',
        'mr' => 'mr',
        'mz' => 'mz',
        'mc' => 'mc',
        'mv' => 'mv',
        'md' => 'md',
        'ma' => 'ma',
        'mn' => 'mn',
        'me' => 'me',
        'ms' => 'ms',
        'jo' => 'jo',
        'la' => 'la',
        'lv' => 'latvian',
        'lt' => 'lithuanian',
        'ly' => 'ly',
        'li' => 'li',
        'lr' => 'lr',
        'ro' => 'romanian',
        'lu' => 'lu',
        'rw' => 'rw',
        'ls' => 'ls',
        'lb' => 'lb',
        're' => 're',
        'ru' => 'russian',
        'cn' => 'china',
        'cf' => 'cf',
        'gf' => 'gf',
        'pf' => 'pf',
        'mp' => 'mp',
        'kp' => 'kp',
        'za' => 'sa',
        'ss' => 'ss',
        'tw' => 'taiwan',
        'va' => 'va',
        'tl' => 'tl',
        'as' => 'as',
        'vi' => 'vi',
        'vg' => 'vg',
        'gq' => 'gq',
        'kr' => 'south_korea',
        'hk' => 'hong_kong',
        'jp' => 'japanese',
    ];
}
