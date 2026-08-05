<?php

namespace Tests\Feature\Api;

use App\Models\Command;
use App\Models\ExecResult;
use App\Models\TimeTrigger;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExecResultsControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_自分のトリガーの実行結果をコマンド名付きで取得できる()
    {
        $user = User::factory()->create();
        $command = Command::factory()->create([
            'target_id' => $user->id,
            'target_type' => 'user',
            'target_name' => '対象コマンド',
        ]);
        $trigger = TimeTrigger::factory()->create([
            'target_id' => $user->id,
            'target_type' => 'user',
            'command_id' => $command->id,
            'timezone' => '+09:00',
        ]);
        ExecResult::factory()->create([
            'command_id' => $command->id,
            'trigger_id' => $trigger->id,
            'exec_time' => '2026-01-01 09:00:00',
            'response_code' => 200,
            'response_header' => '{"Content-Type":["application/json"]}',
            'response_body' => 'ok',
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/api/exec/result/'.$trigger->id);

        $response->assertStatus(200);
        $result = $response->json()[0];
        $this->assertSame(200, $result['response_code']);
        $this->assertSame('ok', $result['response_body']);
        $this->assertSame('対象コマンド', $result['command_name']);
        $this->assertSame('2026-01-01 09:00:00', $result['exec_time']);
    }

    public function test_負のコマンド_i_dは端末モード名へ変換される()
    {
        $user = User::factory()->create();
        $trigger = TimeTrigger::factory()->create([
            'target_id' => $user->id,
            'target_type' => 'user',
            'command_id' => -2,
            'timezone' => '+09:00',
        ]);
        ExecResult::factory()->create([
            'command_id' => -2,
            'trigger_id' => $trigger->id,
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/api/exec/result/'.$trigger->id);

        $response->assertStatus(200);
        $this->assertSame('マナーモード', $response->json()[0]['command_name']);
    }

    public function test_他人のトリガーの実行結果は取得できず403になる()
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $command = Command::factory()->create(['target_id' => $owner->id, 'target_type' => 'user']);
        $trigger = TimeTrigger::factory()->create([
            'target_id' => $owner->id,
            'target_type' => 'user',
            'command_id' => $command->id,
        ]);

        $response = $this->actingAs($other, 'api')->getJson('/api/exec/result/'.$trigger->id);

        $response->assertStatus(403);
    }

    public function test_存在しないトリガー_i_dの結果取得は現状エラーになる()
    {
        // CommandsControllerTest と同様、find() が null を返した後に
        // null のプロパティを読むため警告が例外化され処理が止まる現行仕様の回帰テスト。
        $user = User::factory()->create();

        $this->withoutExceptionHandling();
        $this->expectException(\ErrorException::class);

        $this->actingAs($user, 'api')->getJson('/api/exec/result/999999');
    }
}
