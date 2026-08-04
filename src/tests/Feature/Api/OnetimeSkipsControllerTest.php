<?php

namespace Tests\Feature\Api;

use App\Models\Command;
use App\Models\OnetimeSkip;
use App\Models\TimeTrigger;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OnetimeSkipsControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function createTimeTriggerFor(User $user): TimeTrigger
    {
        $command = Command::factory()->create(['target_id' => $user->id, 'target_type' => 'user']);

        return TimeTrigger::factory()->create([
            'target_id' => $user->id,
            'target_type' => 'user',
            'command_id' => $command->id,
        ]);
    }

    public function test_未使用と使用済みのスキップ件数を返す()
    {
        $user = User::factory()->create();
        $trigger = $this->createTimeTriggerFor($user);
        OnetimeSkip::factory()->count(2)->create(['target_id' => $trigger->id, 'target_type' => 'time']);
        OnetimeSkip::factory()->create(['target_id' => $trigger->id, 'target_type' => 'time', 'deleted_at' => now()]);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/onetime/skip?target_id=' . $trigger->id . '&target_type=time');

        $response->assertStatus(200)->assertExactJson(['skipable_count' => 2, 'skiped_count' => 1]);
    }

    public function test_他人のトリガーに対する一覧参照は403になる()
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $trigger = $this->createTimeTriggerFor($owner);

        $response = $this->actingAs($other, 'api')
            ->getJson('/api/onetime/skip?target_id=' . $trigger->id . '&target_type=time');

        $response->assertStatus(403);
    }

    public function test_登録は所有権を確認せず作成できる()
    {
        // docs/current-architecture.md 10.2:
        // 「ワンタイムスキップの参照と削除は対象トリガーの所有権を確認するが、登録は所有権を確認しない」
        // という現行仕様をそのまま保護する回帰テスト。修正はこのIssueの対象外。
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $trigger = $this->createTimeTriggerFor($owner);

        $response = $this->actingAs($other, 'api')->postJson('/api/onetime/skip', [
            'target_id' => $trigger->id,
            'target_type' => 'time',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('onetime_skips', ['target_id' => $trigger->id, 'target_type' => 'time']);
    }

    public function test_削除は最も古い未使用スキップを対象にする()
    {
        $user = User::factory()->create();
        $trigger = $this->createTimeTriggerFor($user);
        $older = OnetimeSkip::factory()->create(['target_id' => $trigger->id, 'target_type' => 'time']);
        $newer = OnetimeSkip::factory()->create(['target_id' => $trigger->id, 'target_type' => 'time']);

        $response = $this->actingAs($user, 'api')->deleteJson('/api/onetime/skip', [
            'target_id' => $trigger->id,
            'target_type' => 'time',
        ]);

        $response->assertStatus(200);
        $this->assertSame(['result', true], $response->json());
        $this->assertDatabaseMissing('onetime_skips', ['id' => $older->id]);
        $this->assertDatabaseHas('onetime_skips', ['id' => $newer->id]);
    }

    public function test_未使用スキップが無い場合は削除結果がfalseになる()
    {
        $user = User::factory()->create();
        $trigger = $this->createTimeTriggerFor($user);

        $response = $this->actingAs($user, 'api')->deleteJson('/api/onetime/skip', [
            'target_id' => $trigger->id,
            'target_type' => 'time',
        ]);

        $response->assertStatus(200);
        $this->assertSame(['result', false], $response->json());
    }

    public function test_他人のトリガーに対する削除は403になる()
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $trigger = $this->createTimeTriggerFor($owner);
        OnetimeSkip::factory()->create(['target_id' => $trigger->id, 'target_type' => 'time']);

        $response = $this->actingAs($other, 'api')->deleteJson('/api/onetime/skip', [
            'target_id' => $trigger->id,
            'target_type' => 'time',
        ]);

        $response->assertStatus(403);
    }
}
