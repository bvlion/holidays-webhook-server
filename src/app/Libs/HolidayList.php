<?php
namespace App\Libs;

class HolidayList
{
  private $_holidays = [];

  public function clear()
  {
    $this->_holidays = [];
  }

  public function getHolidays(string $code, int $year)
  {
    $key = $code . $year;
    if (array_key_exists($key, $this->_holidays)) {
      return $this->_holidays[$code];
    }

    $holidays_url = sprintf(
      'https://www.googleapis.com/calendar/v3/calendars/%s/events?'.
      'key=%s&timeMin=%s&timeMax=%s&orderBy=startTime&singleEvents=true',
      'japanese__' . $code . '@holiday.calendar.google.com',
      env('GOOGLE_CALENDAR_API_KEY'),
      $year . '-01-01T00%3A00%3A00.000Z',
      $year . '-12-31T00%3A00%3A00.000Z'
    );

    if ($results = file_get_contents($holidays_url, true)) {
      $result_data = json_decode($results);
      $holidays = [];
      foreach ($result_data->items as $item) {
        $holidays[date('Y-m-d', strtotime($item->start->date))] = $item->summary; 
      }
      ksort($holidays);   
    }
    $this->_holidays[$key] = $holidays;
    return $holidays;
  }
}