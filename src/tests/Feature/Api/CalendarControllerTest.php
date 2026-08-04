<?php

namespace Tests\Feature\Api;

use App\Models\Calender;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Doubles\FakeHolidayList;
use Tests\TestCase;

class CalendarControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function fakeHolidays(array $holidaysByKey)
    {
        $fake = new FakeHolidayList($holidaysByKey);
        $this->app->instance('HolidayList', $fake);

        return $fake;
    }

    public function test_Google_Calendarの祝日のみ存在する場合はholidayがtrueでforceはfalse()
    {
        $this->fakeHolidays(['jp2026' => ['2026-01-01' => '元日']]);
        $user = User::factory()->create(['country_code' => 'jp']);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/calendar/holiday?type=user&date=2026-01-01');

        $response->assertStatus(200)->assertExactJson(['holiday' => true, 'force' => false]);
    }

    public function test_Google_Calendarが休日でなくても個人カレンダーの祝日設定が優先される()
    {
        $this->fakeHolidays(['jp2026' => []]);
        $user = User::factory()->create(['country_code' => 'jp']);
        Calender::factory()->create([
            'target_id' => $user->id,
            'target_type' => 'user',
            'target_date' => '2026-01-02',
            'is_holiday' => 1,
        ]);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/calendar/holiday?type=user&date=2026-01-02');

        $response->assertStatus(200)->assertExactJson(['holiday' => true, 'force' => true]);
    }

    public function test_Google_Calendarが休日でも個人カレンダーの非祝日設定が優先される()
    {
        $this->fakeHolidays(['jp2026' => ['2026-01-01' => '元日']]);
        $user = User::factory()->create(['country_code' => 'jp']);
        Calender::factory()->create([
            'target_id' => $user->id,
            'target_type' => 'user',
            'target_date' => '2026-01-01',
            'is_holiday' => 0,
        ]);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/calendar/holiday?type=user&date=2026-01-01');

        $response->assertStatus(200)->assertExactJson(['holiday' => false, 'force' => true]);
    }

    public function test_typeがgroupの場合は同じグループの別ユーザーでも上書き結果を共有する()
    {
        $this->fakeHolidays(['jp2026' => []]);
        $group = Group::factory()->create();
        $ownerUser = User::factory()->create(['groups_id' => $group->id, 'country_code' => 'jp']);
        $memberUser = User::factory()->create(['groups_id' => $group->id, 'country_code' => 'jp', 'owner_flag' => 0]);
        Calender::factory()->create([
            'target_id' => $group->id,
            'target_type' => 'group',
            'target_date' => '2026-03-03',
            'is_holiday' => 1,
        ]);

        $response = $this->actingAs($memberUser, 'api')
            ->getJson('/api/calendar/holiday?type=group&date=2026-03-03');

        $response->assertStatus(200)->assertExactJson(['holiday' => true, 'force' => true]);
    }

    public function test_typeまたはdateが無い場合は404を返す()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')->getJson('/api/calendar/holiday?type=user');

        $response->assertStatus(404);
    }

    public function test_不正な日付文字列は例外にならず1970年として扱われる()
    {
        $this->fakeHolidays([]);
        $user = User::factory()->create(['country_code' => 'jp']);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/calendar/holiday?type=user&date=not-a-date');

        $response->assertStatus(200)->assertExactJson(['holiday' => false, 'force' => false]);
    }

    public function test_country_codeパラメータを指定するとユーザーの既定国コードより優先される()
    {
        $fake = $this->fakeHolidays(['us2026' => ['2026-07-04' => 'Independence Day']]);
        $user = User::factory()->create(['country_code' => 'jp']);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/calendar/holiday?type=user&date=2026-07-04&country_code=us');

        $response->assertStatus(200)->assertExactJson(['holiday' => true, 'force' => false]);
        $this->assertContains('us2026', $fake->requestedKeys);
    }

    public function test_upsertで同じ対象日に登録すると更新されて重複作成されない()
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->postJson('/api/calendar/upsert', [
            'type' => 'user',
            'date' => '2026-05-05',
            'holiday' => 1,
        ])->assertStatus(200);

        $this->actingAs($user, 'api')->postJson('/api/calendar/upsert', [
            'type' => 'user',
            'date' => '2026-05-05',
            'holiday' => 0,
        ])->assertStatus(200);

        $this->assertSame(1, Calender::where('target_id', $user->id)
            ->where('target_type', 'user')
            ->where('target_date', '2026-05-05')
            ->count());
        $this->assertSame(0, Calender::where('target_id', $user->id)
            ->where('target_type', 'user')
            ->where('target_date', '2026-05-05')
            ->first()->is_holiday);
    }
}
