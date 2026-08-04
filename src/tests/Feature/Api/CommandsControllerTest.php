<?php

namespace Tests\Feature\Api;

use App\Models\Command;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CommandsControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_一覧はユーザー自身と所属グループのコマンドを返す()
    {
        $group = Group::factory()->create();
        $user = User::factory()->create(['groups_id' => $group->id]);
        $ownCommand = Command::factory()->create(['target_id' => $user->id, 'target_type' => 'user']);
        $groupCommand = Command::factory()->create(['target_id' => $group->id, 'target_type' => 'group']);
        $otherUsersCommand = Command::factory()->create(['target_id' => $user->id + 1000, 'target_type' => 'user']);

        $response = $this->actingAs($user, 'api')->getJson('/api/commands');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($ownCommand->id, $ids);
        $this->assertContains($groupCommand->id, $ids);
        $this->assertNotContains($otherUsersCommand->id, $ids);
    }

    public function test_ユーザー対象の削除済みコマンドは一覧の絞り込み条件をすり抜けて表示される()
    {
        // docs/current-architecture.md 10.3 に記載された現行クエリの仕様（バグ）を保護する回帰テスト。
        // whereNull('deleted_at') が SQL 上 OR の右辺(グループ条件)にしか掛からないため、
        // ユーザー対象は削除済みでも一覧に残る。
        $user = User::factory()->create();
        $deletedUserCommand = Command::factory()->create([
            'target_id' => $user->id,
            'target_type' => 'user',
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/api/commands');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($deletedUserCommand->id, $ids);
    }

    public function test_グループ対象の削除済みコマンドは一覧から除外される()
    {
        $group = Group::factory()->create();
        $user = User::factory()->create(['groups_id' => $group->id]);
        $deletedGroupCommand = Command::factory()->create([
            'target_id' => $group->id,
            'target_type' => 'group',
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/api/commands');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertNotContains($deletedGroupCommand->id, $ids);
    }

    public function test_必須項目が無い場合は登録時に400を返す()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')->postJson('/api/commands', [
            'target_type' => 'user',
        ]);

        $response->assertStatus(400);
    }

    public function test_target_typeがuserの場合は自分のIDが対象になる()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')->postJson('/api/commands', [
            'target_name' => 'テストコマンド',
            'target_type' => 'user',
            'url' => 'https://example.test/webhook',
            'method' => 'GET',
            'body_type' => 'json',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHasCommand($user->id, 'user', 'テストコマンド');
    }

    public function test_target_typeがgroupの場合は所属グループのIDが対象になる()
    {
        $group = Group::factory()->create();
        $user = User::factory()->create(['groups_id' => $group->id]);

        $response = $this->actingAs($user, 'api')->postJson('/api/commands', [
            'target_name' => 'グループコマンド',
            'target_type' => 'group',
            'url' => 'https://example.test/webhook',
            'method' => 'POST',
            'body_type' => 'form_params',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHasCommand($group->id, 'group', 'グループコマンド');
    }

    public function test_所有者は自分のコマンドを部分更新できる()
    {
        $user = User::factory()->create();
        $command = Command::factory()->create([
            'target_id' => $user->id,
            'target_type' => 'user',
            'target_name' => '旧名称',
            'url' => 'https://example.test/old',
        ]);

        $response = $this->actingAs($user, 'api')->putJson('/api/commands/' . $command->id, [
            'target_name' => '新名称',
        ]);

        $response->assertStatus(200);
        $command->refresh();
        $this->assertSame('新名称', $command->target_name);
        $this->assertSame('https://example.test/old', $command->url);
    }

    public function test_他人のコマンドは更新できず403になる()
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $command = Command::factory()->create(['target_id' => $owner->id, 'target_type' => 'user']);

        $response = $this->actingAs($other, 'api')->putJson('/api/commands/' . $command->id, [
            'target_name' => '書き換え',
        ]);

        $response->assertStatus(403);
        $command->refresh();
        $this->assertNotSame('書き換え', $command->target_name);
    }

    public function test_存在しないコマンドの更新は現状エラーになる()
    {
        // docs/current-architecture.md 10.2:
        // 「存在しないコマンドまたはトリガーに対し...更新、削除...は取得結果を参照して500エラーになり得る」
        // find() が null を返した後 null のプロパティを読むため、警告が例外化されて処理が止まる。
        // 修正はこのIssueの対象外であり、現行仕様を保護する回帰テストとして記録する。
        $user = User::factory()->create();

        $this->withoutExceptionHandling();
        $this->expectException(\ErrorException::class);

        $this->actingAs($user, 'api')->putJson('/api/commands/999999', [
            'target_name' => '書き換え',
        ]);
    }

    public function test_所有者は自分のコマンドを削除でき論理削除される()
    {
        $user = User::factory()->create();
        $command = Command::factory()->create(['target_id' => $user->id, 'target_type' => 'user']);

        $response = $this->actingAs($user, 'api')->deleteJson('/api/commands/' . $command->id);

        $response->assertStatus(200);
        $this->assertNotNull($command->fresh()->deleted_at);
    }

    public function test_他人のコマンドは削除できず403になる()
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $command = Command::factory()->create(['target_id' => $owner->id, 'target_type' => 'user']);

        $response = $this->actingAs($other, 'api')->deleteJson('/api/commands/' . $command->id);

        $response->assertStatus(403);
        $this->assertNull($command->fresh()->deleted_at);
    }

    private function assertDatabaseHasCommand(int $targetId, string $targetType, string $targetName): void
    {
        $this->assertDatabaseHas('commands', [
            'target_id' => $targetId,
            'target_type' => $targetType,
            'target_name' => $targetName,
        ]);
    }
}
