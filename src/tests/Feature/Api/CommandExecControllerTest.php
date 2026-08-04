<?php

namespace Tests\Feature\Api;

use App\Models\Command;
use App\Models\SummarizeCommand;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CommandExecControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // 既定では応答を持たない MockHandler を登録する。認可や404判定が回帰して
        // 実際にHTTPリクエストを送る経路へ入った場合でも、外部ネットワークへは
        // 接続せずキューが空のまま例外になりテストが失敗する。個別の応答が必要な
        // テストは bindMockClient() で明示的に上書きする。
        $this->bindMockClient([]);
    }

    /**
     * @param array $responses GuzzleHttp\Psr7\Response または \Throwable の配列
     */
    private function bindMockClient(array $responses): void
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $this->app->instance(Client::class, new Client(['handler' => $handlerStack]));
    }

    public function test_自分が所有するコマンドを実行できる()
    {
        $this->bindMockClient([new Response(200, [], 'ok-body')]);
        $user = User::factory()->create();
        $command = Command::factory()->create([
            'target_id' => $user->id,
            'target_type' => 'user',
            'target_name' => '実行対象コマンド',
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/exec/command/' . $command->id);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame('実行対象コマンド', $body[0]['name']);
        $this->assertSame(200, $body[0]['response_code']);
        // saveResult() は PSR-7 の StreamInterface をそのまま配列に詰めているため、
        // json_encode() では本文が空オブジェクトへ変換される(公開プロパティを持たないため)。
        // 実際のレスポンス本文はAPI応答へ反映されない、という現行の仕様を保護する。
        $this->assertSame([], $body[0]['response_body']);
    }

    public function test_他人が所有するコマンドは実行できず403になる()
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $command = Command::factory()->create([
            'target_id' => $owner->id,
            'target_type' => 'user',
        ]);

        $response = $this->actingAs($other, 'api')
            ->postJson('/api/exec/command/' . $command->id);

        $response->assertStatus(403);
    }

    public function test_削除済みコマンドの実行は404になる()
    {
        $user = User::factory()->create();
        $command = Command::factory()->create([
            'target_id' => $user->id,
            'target_type' => 'user',
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/exec/command/' . $command->id);

        $response->assertStatus(404);
    }

    public function test_存在しないコマンドの実行は404になる()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/exec/command/999999');

        $response->assertStatus(404);
    }

    public function test_まとめ実行で複数コマンドの結果がまとめて返る()
    {
        $this->bindMockClient([
            new Response(200, [], 'first'),
            new Response(200, [], 'second'),
        ]);
        $user = User::factory()->create();
        $commandA = Command::factory()->create([
            'target_id' => $user->id,
            'target_type' => 'user',
            'target_name' => 'コマンドA',
        ]);
        $commandB = Command::factory()->create([
            'target_id' => $user->id,
            'target_type' => 'user',
            'target_name' => 'コマンドB',
        ]);
        $summary = SummarizeCommand::factory()->create([
            'target_id' => $user->id,
            'target_type' => 'user',
            'commands' => json_encode([$commandA->id, $commandB->id]),
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/exec/summary/' . $summary->id);

        $response->assertStatus(200);
        $results = $response->json();
        $this->assertCount(2, $results);
        $names = collect($results)->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['コマンドA', 'コマンドB'], $names);
        $this->assertTrue(collect($results)->every(fn ($r) => $r['response_code'] === 200));
    }

    public function test_まとめ実行はまとめコマンド自体の所有権だけを確認し内包コマンドの所有権は確認しない()
    {
        // Issue #212 / docs/current-architecture.md 10.2 に記載された現行仕様をそのまま保護する回帰テスト。
        $this->bindMockClient([new Response(200, [], 'other-owner-body')]);
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $commandOfOther = Command::factory()->create([
            'target_id' => $otherOwner->id,
            'target_type' => 'user',
            'target_name' => '他人のコマンド',
        ]);
        $summary = SummarizeCommand::factory()->create([
            'target_id' => $owner->id,
            'target_type' => 'user',
            'commands' => json_encode([$commandOfOther->id]),
        ]);

        $response = $this->actingAs($owner, 'api')
            ->postJson('/api/exec/summary/' . $summary->id);

        $response->assertStatus(200);
        $this->assertSame('他人のコマンド', $response->json()[0]['name']);
        $this->assertSame(200, $response->json()[0]['response_code']);
    }

    public function test_外部HTTPがエラー応答を返した場合はそのステータスが結果へ反映される()
    {
        // Guzzleのhttp_errorsミドルウェアが4xx/5xx応答を例外化するため、
        // 実際のHTTPエラー応答と同じ経路で ServerException (RequestExceptionのサブクラス) が送出される。
        $this->bindMockClient([new Response(500, [], 'server-error-body')]);
        $user = User::factory()->create();
        $command = Command::factory()->create(['target_id' => $user->id, 'target_type' => 'user']);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/exec/command/' . $command->id);

        $response->assertStatus(200);
        $body = $response->json()[0];
        $this->assertSame(500, $body['response_code']);
    }

    public function test_レスポンスを伴わないRequestExceptionは現状TypeErrorになる()
    {
        // 保存処理は必ずレスポンスオブジェクトを受け取る前提だが、
        // RequestException::getResponse() は null を返し得るため現状は例外になる
        // (docs/current-architecture.md 10.4)。修正はこのIssueの対象外であり、
        // 現行仕様を保護する回帰テストとして記録する。
        $this->bindMockClient([
            new RequestException('connection failed', new Psr7Request('GET', 'http://example.test')),
        ]);
        $user = User::factory()->create();
        $command = Command::factory()->create(['target_id' => $user->id, 'target_type' => 'user']);

        $this->withoutExceptionHandling();
        $this->expectException(\TypeError::class);

        $this->actingAs($user, 'api')->postJson('/api/exec/command/' . $command->id);
    }
}
